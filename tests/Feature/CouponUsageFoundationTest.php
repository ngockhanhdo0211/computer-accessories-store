<?php

namespace Tests\Feature;

use App\Actions\ConsumeCouponUsage;
use App\Actions\CreatePaymentAttempt;
use App\Actions\DeleteCoupon;
use App\Actions\ReleaseCouponUsage;
use App\Actions\ReleaseStockReservations;
use App\Enums\CouponUsageStatus;
use App\Enums\PaymentStatus;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\User;
use App\ValueObjects\CheckoutRecipient;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class CouponUsageFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function recipient(): CheckoutRecipient
    {
        return new CheckoutRecipient('Nguyen Minh Anh', 'receiver@example.test', '0912345678', 'Ha Noi', 'Cau Giay', 'Dich Vong', '12 Tran Thai Tong');
    }

    private function customerWithCart(): User
    {
        $customer = User::factory()->create();
        $product = Product::factory()->inStock(20)->create(['price_vnd' => 100_000]);
        CartItem::factory()->for($customer)->for($product)->create(['quantity' => 1]);

        return $customer;
    }

    private function attempt(User $customer, Coupon $coupon, ?CarbonImmutable $at = null, ?string $key = null): PaymentAttempt
    {
        return app(CreatePaymentAttempt::class)->handle(
            $customer, $this->recipient(), $key ?? (string) Str::uuid(), $coupon->code, $at,
        );
    }

    private function paidOrder(PaymentAttempt $attempt): Order
    {
        $attempt->forceFill(['status' => PaymentStatus::Paid, 'verified_at' => now()])->save();

        return Order::factory()->forVerifiedAttempt($attempt)->create([
            'coupon_snapshot_json' => $attempt->pricing_snapshot_json['coupon'],
        ]);
    }

    public function test_total_and_customer_capacity_are_atomic_and_unlimited_allows_multiple(): void
    {
        $limited = Coupon::factory()->create(['max_uses' => 1, 'max_uses_per_user' => null]);
        $this->attempt($this->customerWithCart(), $limited);
        try {
            $this->attempt($this->customerWithCart(), $limited);
            $this->fail('Total capacity was overbooked.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('coupon_code', $exception->errors());
        }
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertDatabaseCount('stock_reservations', 1);
        $this->assertDatabaseCount('coupon_usages', 1);

        $unlimited = Coupon::factory()->create();
        $this->attempt($this->customerWithCart(), $unlimited);
        $this->attempt($this->customerWithCart(), $unlimited);
        $this->assertSame(2, $unlimited->usages()->count());

        $customerLimited = Coupon::factory()->create(['max_uses_per_user' => 1]);
        $customer = $this->customerWithCart();
        $this->attempt($customer, $customerLimited);
        $this->expectException(ValidationException::class);
        $this->attempt($customer, $customerLimited);
    }

    public function test_coupon_attempt_replay_is_idempotent_and_coupon_payload_change_conflicts(): void
    {
        $customer = $this->customerWithCart();
        $coupon = Coupon::factory()->create(['code' => 'SAME10']);
        $other = Coupon::factory()->create(['code' => 'OTHER10']);
        $key = (string) Str::uuid();
        $first = $this->attempt($customer, $coupon, null, $key);
        $replay = $this->attempt($customer, $coupon, null, $key);
        $this->assertTrue($first->is($replay));
        $this->assertDatabaseCount('coupon_usages', 1);
        $this->assertDatabaseCount('stock_reservations', 1);

        try {
            $this->attempt($customer, $other, null, $key);
            $this->fail('Changed Coupon payload was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('request_key', $exception->errors());
        }
    }

    public function test_inactive_or_exhausted_coupon_rolls_back_attempt_stock_and_usage(): void
    {
        $customer = $this->customerWithCart();
        foreach ([
            Coupon::factory()->inactive()->create(),
            Coupon::factory()->create(['starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]),
            Coupon::factory()->create(['starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]),
        ] as $coupon) {
            try {
                $this->attempt($customer, $coupon);
                $this->fail('Invalid Coupon was reserved.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('coupon_code', $exception->errors());
            }
        }
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->assertDatabaseCount('coupon_usages', 0);
    }

    public function test_membership_minimum_scope_and_stock_are_rechecked_before_any_write(): void
    {
        $customer = $this->customerWithCart();
        $cartProduct = $customer->cartItems()->firstOrFail()->product;
        $otherProduct = Product::factory()->inStock(10)->create(['price_vnd' => 100_000]);
        $scopeCoupon = Coupon::factory()->create(['scope' => 'product']);
        $scopeCoupon->products()->attach($otherProduct->id);

        foreach ([
            Coupon::factory()->create(['required_tier' => 'vang']),
            Coupon::factory()->create(['min_subtotal_vnd' => 200_000]),
            $scopeCoupon,
        ] as $coupon) {
            try {
                $this->attempt($customer, $coupon);
                $this->fail('Coupon definition was not rechecked.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('coupon_code', $exception->errors());
            }
        }

        DB::table('products')->where('id', $cartProduct->id)->update(['sellable_quantity' => 0]);
        try {
            $this->attempt($customer, Coupon::factory()->create());
            $this->fail('Unavailable stock created partial checkout rows.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cart', $exception->errors());
        }
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->assertDatabaseCount('coupon_usages', 0);
    }

    public function test_release_and_expiration_are_atomic_idempotent_and_keep_attempt_unpaid(): void
    {
        $now = CarbonImmutable::parse('2026-09-29 12:00:00', 'UTC');
        $this->travelTo($now);
        $attempt = $this->attempt($this->customerWithCart(), Coupon::factory()->create(), $now->subMinutes(16));
        $attemptSnapshots = $attempt->only(['items_snapshot_json', 'recipient_snapshot_json', 'pricing_snapshot_json']);
        app(ReleaseStockReservations::class)->handle($attempt, $now);
        $releasedAt = $attempt->couponUsage->fresh()->released_at;
        $this->assertSame(CouponUsageStatus::Released, $attempt->couponUsage->fresh()->status);
        $this->assertNotNull($attempt->stockReservations()->first()->released_at);
        app(ReleaseStockReservations::class)->handle($attempt, $now->addMinute());
        $this->assertTrue($releasedAt->equalTo($attempt->couponUsage->fresh()->released_at));
        $this->assertSame(PaymentStatus::Unpaid, $attempt->fresh()->status);
        $this->assertSame($attemptSnapshots, $attempt->fresh()->only(array_keys($attemptSnapshots)));
    }

    public function test_failure_during_coupon_release_rolls_back_stock_release(): void
    {
        $attempt = $this->attempt($this->customerWithCart(), Coupon::factory()->create());
        $this->mock(ReleaseCouponUsage::class, function ($mock): void {
            $mock->shouldReceive('releaseLocked')->once()->andThrow(new RuntimeException('forced release failure'));
        });

        try {
            app(ReleaseStockReservations::class)->handle($attempt);
            $this->fail('Partial release was committed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced release failure', $exception->getMessage());
        }

        $this->assertNull($attempt->stockReservations()->firstOrFail()->released_at);
        $this->assertSame(CouponUsageStatus::Reserved, $attempt->couponUsage->fresh()->status);
    }

    public function test_failure_during_stock_release_leaves_coupon_reserved(): void
    {
        $attempt = $this->attempt($this->customerWithCart(), Coupon::factory()->create());
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER coupon_usage_qa_stock_release_failure BEFORE UPDATE ON stock_reservations
            BEGIN SELECT RAISE(ABORT, 'forced stock release failure'); END
            SQL);

        try {
            app(ReleaseStockReservations::class)->handle($attempt);
            $this->fail('Stock release failure did not abort the transaction.');
        } catch (QueryException) {
            $this->assertNull($attempt->stockReservations()->firstOrFail()->released_at);
            $this->assertSame(CouponUsageStatus::Reserved, $attempt->couponUsage->fresh()->status);
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS coupon_usage_qa_stock_release_failure');
        }
    }

    public function test_expired_reserved_and_released_rows_do_not_hold_capacity_at_boundary(): void
    {
        $now = CarbonImmutable::parse('2026-09-29 12:00:00', 'UTC');
        $this->travelTo($now);
        $coupon = Coupon::factory()->create(['max_uses' => 1]);
        $expired = $this->attempt($this->customerWithCart(), $coupon, $now->subMinutes(15));
        $this->assertFalse($expired->couponUsage->isHoldingCapacityAt($now));
        $this->assertSame(0, $coupon->usages()->holdingCapacityAt($now)->count());

        $replacement = $this->attempt($this->customerWithCart(), $coupon, $now);
        app(ReleaseCouponUsage::class)->handle($replacement->couponUsage, 'manual release', $now->addSecond());
        $this->assertSame(0, $coupon->usages()->holdingCapacityAt($now->addSeconds(2))->count());
    }

    public function test_coupon_with_usage_cannot_be_deleted(): void
    {
        $coupon = Coupon::factory()->create();
        $this->attempt($this->customerWithCart(), $coupon);
        try {
            app(DeleteCoupon::class)->handle($coupon, User::factory()->admin()->create());
            $this->fail('Coupon with Usage was deleted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('coupon', $exception->errors());
        }
        $this->assertDatabaseHas('coupons', ['id' => $coupon->id]);
    }

    public function test_unrelated_coupon_delete_database_error_is_not_mapped_to_usage_validation(): void
    {
        $coupon = Coupon::factory()->create();
        $actor = User::factory()->admin()->create();
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER coupon_usage_qa_unrelated_delete_failure BEFORE DELETE ON coupons
            BEGIN SELECT RAISE(ABORT, 'unrelated coupon delete failure'); END
            SQL);

        try {
            app(DeleteCoupon::class)->handle($coupon, $actor);
            $this->fail('Unrelated database error was swallowed.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('unrelated coupon delete failure', $exception->getMessage());
            $this->assertDatabaseHas('coupons', ['id' => $coupon->id]);
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS coupon_usage_qa_unrelated_delete_failure');
        }
    }

    public function test_normal_and_late_consume_validate_state_relationships_and_capacity(): void
    {
        $mismatchCoupon = Coupon::factory()->create();
        $mismatchAttempt = $this->attempt($this->customerWithCart(), $mismatchCoupon);
        $mismatchAttempt->forceFill(['status' => PaymentStatus::Paid, 'verified_at' => now()])->save();
        $mismatchSnapshot = $mismatchAttempt->pricing_snapshot_json['coupon'];
        $mismatchSnapshot['code'] = 'DIFFERENT-SNAPSHOT';
        $mismatchOrder = Order::factory()->forVerifiedAttempt($mismatchAttempt)->create([
            'coupon_snapshot_json' => $mismatchSnapshot,
        ]);
        try {
            app(ConsumeCouponUsage::class)->handle($mismatchAttempt->couponUsage, $mismatchOrder);
            $this->fail('Mismatched Order and Payment Attempt Coupon snapshots were accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('coupon_usage', $exception->errors());
            $this->assertSame(CouponUsageStatus::Reserved, $mismatchAttempt->couponUsage->fresh()->status);
        }

        $coupon = Coupon::factory()->create(['max_uses' => 1]);
        $attempt = $this->attempt($this->customerWithCart(), $coupon);
        $order = $this->paidOrder($attempt);
        $usage = app(ConsumeCouponUsage::class)->handle($attempt->couponUsage, $order);
        $this->assertSame(CouponUsageStatus::Consumed, $usage->status);
        $this->assertFalse($usage->late_callback_exception);
        $this->assertTrue($usage->order->is($order));
        $this->assertTrue($usage->is(app(ConsumeCouponUsage::class)->handle($usage, $order)));
        try {
            $this->attempt($this->customerWithCart(), $coupon);
            $this->fail('Consumed usage did not hold total capacity.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('coupon_code', $exception->errors());
        }
        try {
            app(ConsumeCouponUsage::class)->handle($usage, Order::factory()->create());
            $this->fail('Consumed usage was replayed with another Order.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('coupon_usage', $exception->errors());
        }
        try {
            app(ReleaseCouponUsage::class)->handle($usage, 'invalid');
            $this->fail('Consumed usage was released.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('coupon_usage', $exception->errors());
        }

        $lateCoupon = Coupon::factory()->create(['max_uses' => 1]);
        $lateAttempt = $this->attempt($this->customerWithCart(), $lateCoupon);
        app(ReleaseCouponUsage::class)->handle($lateAttempt->couponUsage, 'expired');
        $lateOrder = $this->paidOrder($lateAttempt);
        $late = app(ConsumeCouponUsage::class)->handle($lateAttempt->couponUsage, $lateOrder, true);
        $this->assertTrue($late->late_callback_exception);
        $this->assertNotNull($late->released_at);
        $this->assertSame(1, $lateCoupon->usages()->holdingCapacityAt(now())->count());

        $blockedCoupon = Coupon::factory()->create(['max_uses' => 1]);
        $blockedAttempt = $this->attempt($this->customerWithCart(), $blockedCoupon);
        app(ReleaseCouponUsage::class)->handle($blockedAttempt->couponUsage, 'expired');
        $blockedOrder = $this->paidOrder($blockedAttempt);
        $this->attempt($this->customerWithCart(), $blockedCoupon);
        try {
            app(ConsumeCouponUsage::class)->handle($blockedAttempt->couponUsage, $blockedOrder, true);
            $this->fail('Late callback exceeded capacity.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('coupon_code', $exception->errors());
        }
        $blocked = $blockedAttempt->couponUsage->fresh();
        $this->assertSame(CouponUsageStatus::Released, $blocked->status);
        $this->assertNull($blocked->order_id);
        $this->assertNull($blocked->consumed_at);
        $this->assertFalse($blocked->late_callback_exception);
    }
}
