<?php

namespace Tests\Feature;

use App\Actions\CalculateAvailableStock;
use App\Actions\CreatePaymentAttempt;
use App\Actions\ReleaseStockReservations;
use App\Enums\PaymentStatus;
use App\Models\CartItem;
use App\Models\InventoryTransaction;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\StockReservation;
use App\Models\User;
use App\ValueObjects\CheckoutRecipient;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaymentAttemptFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function recipient(array $overrides = []): CheckoutRecipient
    {
        return new CheckoutRecipient(...array_values(array_merge([
            'name' => 'Nguyen Minh Anh',
            'email' => 'receiver@example.test',
            'phone' => '0912345678',
            'province' => 'Ha Noi',
            'district' => 'Cau Giay',
            'ward' => 'Dich Vong',
            'addressLine' => '12 Tran Thai Tong',
        ], $overrides)));
    }

    private function addLine(User $customer, array $attributes = [], int $quantity = 1): Product
    {
        $product = Product::factory()->inStock(max(10, $quantity))->create($attributes);
        CartItem::factory()->for($customer)->for($product)->create(['quantity' => $quantity]);

        return $product;
    }

    public function test_attempt_recalculates_and_stores_independent_snapshots_with_fifteen_minute_reservations(): void
    {
        $at = CarbonImmutable::parse('2026-09-28 03:04:05.123456', 'UTC');
        $customer = User::factory()->create();
        $first = $this->addLine($customer, ['price_vnd' => 200_000, 'sale_price_vnd' => 150_000], 2);
        $second = $this->addLine($customer, ['price_vnd' => 100_000], 1);
        $before = [$first->only(['sellable_quantity', 'damaged_quantity', 'sold_quantity']),
            $second->only(['sellable_quantity', 'damaged_quantity', 'sold_quantity'])];

        $attempt = app(CreatePaymentAttempt::class)->handle(
            $customer,
            $this->recipient(),
            (string) Str::uuid(),
            null,
            $at,
        );

        $this->assertSame(PaymentStatus::Unpaid, $attempt->status);
        $this->assertSame(430_000, $attempt->amount_vnd);
        $this->assertSame(30_000, $attempt->shipping_fee_vnd);
        $this->assertNull($attempt->coupon_id);
        $this->assertSame($at->addMinutes(15)->format('Y-m-d H:i:s.u'), $attempt->expires_at->format('Y-m-d H:i:s.u'));
        $this->assertSame(900, $attempt->expires_at->getTimestamp() - $at->getTimestamp());
        $this->assertSame([$first->id, $second->id], array_column($attempt->items_snapshot_json, 'product_id'));
        $this->assertSame([2, 1], array_column($attempt->items_snapshot_json, 'quantity'));
        $this->assertSame('receiver@example.test', $attempt->recipient_snapshot_json['recipient_email']);
        $this->assertSame(430_000, $attempt->pricing_snapshot_json['total_vnd']);
        $this->assertDoesNotMatchRegularExpression(
            '/password|token|secret/i',
            json_encode([
                $attempt->items_snapshot_json,
                $attempt->recipient_snapshot_json,
                $attempt->pricing_snapshot_json,
            ], JSON_THROW_ON_ERROR),
        );
        $this->assertCount(2, $attempt->stockReservations);
        $this->assertSame([$first->id, $second->id], $attempt->stockReservations->pluck('product_id')->all());
        $this->assertTrue($attempt->stockReservations->every(
            fn (StockReservation $reservation) => $reservation->expires_at->equalTo($attempt->expires_at)
                && $reservation->released_at === null
                && $reservation->consumed_at === null,
        ));
        $this->assertSame($before[0], $first->fresh()->only(array_keys($before[0])));
        $this->assertSame($before[1], $second->fresh()->only(array_keys($before[1])));
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertDatabaseCount('cart_items', 2);
        $this->assertFalse(Schema::hasTable('orders'));
        $this->assertFalse(Schema::hasTable('coupon_usages'));
    }

    public function test_coupon_quote_is_rejected_before_any_attempt_or_reservation_is_created(): void
    {
        $customer = User::factory()->create();
        $this->addLine($customer);

        try {
            app(CreatePaymentAttempt::class)->handle(
                $customer,
                $this->recipient(),
                (string) Str::uuid(),
                'SALE10',
            );
            $this->fail('A coupon-backed Payment Attempt was created without Coupon Usage.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('coupon_code', $exception->errors());
        }

        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
    }

    public function test_idempotency_replay_returns_same_attempt_and_changed_payload_conflicts(): void
    {
        $customer = User::factory()->create();
        $line = $this->addLine($customer, [], 2);
        $this->addLine($customer);
        $key = (string) Str::uuid();
        $action = app(CreatePaymentAttempt::class);

        $first = $action->handle($customer, $this->recipient(), $key);
        DB::table('payment_attempts')->where('id', $first->id)->update([
            'items_snapshot_json' => json_encode(array_reverse($first->items_snapshot_json), JSON_THROW_ON_ERROR),
            'recipient_snapshot_json' => json_encode(array_reverse(
                $first->recipient_snapshot_json,
                preserve_keys: true,
            ), JSON_THROW_ON_ERROR),
        ]);
        $replay = $action->handle($customer, $this->recipient(), $key);
        $this->assertTrue($first->is($replay));
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertDatabaseCount('stock_reservations', 2);

        foreach ([
            fn () => $action->handle($customer, $this->recipient(['addressLine' => '99 Nguyen Trai']), $key),
            fn () => $action->handle($customer, $this->recipient(['province' => 'Ho Chi Minh']), $key),
            function () use ($action, $customer, $line, $key) {
                CartItem::query()->where('user_id', $customer->id)->where('product_id', $line->id)->update(['quantity' => 1]);

                return $action->handle($customer, $this->recipient(), $key);
            },
        ] as $conflict) {
            try {
                $conflict();
                $this->fail('Changed idempotency payload was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('request_key', $exception->errors());
            }
        }
    }

    public function test_new_attempt_recalculates_current_product_price_and_shipping_fee(): void
    {
        $customer = User::factory()->create();
        $product = $this->addLine($customer, ['price_vnd' => 200_000], 2);
        $product->update(['price_vnd' => 250_000]);
        DB::table('shipping_rates')->where('region_key', 'ha_noi')->update(['fee_vnd' => 35_000]);

        $attempt = app(CreatePaymentAttempt::class)->handle(
            $customer,
            $this->recipient(),
            (string) Str::uuid(),
        );

        $this->assertSame(250_000, $attempt->items_snapshot_json[0]['unit_price_vnd']);
        $this->assertSame(500_000, $attempt->pricing_snapshot_json['cart_subtotal_vnd']);
        $this->assertSame(35_000, $attempt->shipping_fee_vnd);
        $this->assertSame(535_000, $attempt->amount_vnd);
    }

    public function test_request_key_is_customer_scoped_and_no_public_payment_route_exists(): void
    {
        $key = (string) Str::uuid();
        $firstCustomer = User::factory()->create();
        $secondCustomer = User::factory()->create();
        $this->addLine($firstCustomer);
        $this->addLine($secondCustomer);

        $first = app(CreatePaymentAttempt::class)->handle($firstCustomer, $this->recipient(), $key);
        $second = app(CreatePaymentAttempt::class)->handle($secondCustomer, $this->recipient(), $key);
        $this->assertNotSame($first->id, $second->id);
        $this->assertDatabaseCount('payment_attempts', 2);
        $this->assertFalse(collect(app('router')->getRoutes())->contains(
            fn ($route) => str_contains((string) $route->getName(), 'payment')
                || str_contains($route->uri(), 'payment'),
        ));
    }

    public function test_invalid_key_non_customer_and_client_owned_fields_are_rejected_or_ignored(): void
    {
        $customer = User::factory()->create();
        $product = $this->addLine($customer, [
            'price_vnd' => 250_000,
            'sellable_quantity' => 5,
        ]);

        foreach (['not-a-uuid', str_repeat('a', 36)] as $key) {
            try {
                app(CreatePaymentAttempt::class)->handle($customer, $this->recipient(), $key);
                $this->fail('Invalid request key was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('request_key', $exception->errors());
            }
        }

        foreach ([
            User::factory()->employee()->create(),
            User::factory()->admin()->create(),
            User::factory()->locked()->create(),
            User::factory()->inactive()->create(),
        ] as $actor) {
            try {
                app(CreatePaymentAttempt::class)->handle($actor, $this->recipient(), (string) Str::uuid());
                $this->fail('A non-customer created a Payment Attempt.');
            } catch (AuthorizationException) {
                $this->assertDatabaseCount('payment_attempts', 0);
            }
        }

        $attempt = app(CreatePaymentAttempt::class)->handle($customer, $this->recipient(), (string) Str::uuid());
        $this->assertSame(280_000, $attempt->amount_vnd);
        $this->assertSame(5, $product->fresh()->sellable_quantity);
    }

    public function test_active_reservations_reduce_available_stock_but_expired_and_released_do_not(): void
    {
        $at = CarbonImmutable::parse('2026-09-28 10:00:00', 'UTC');
        $customer = User::factory()->create();
        $product = $this->addLine($customer, ['sellable_quantity' => 7], 3);
        $attempt = app(CreatePaymentAttempt::class)->handle($customer, $this->recipient(), (string) Str::uuid(), null, $at);
        $availability = app(CalculateAvailableStock::class);

        $this->assertSame(4, $availability->forProduct($product->fresh(), null, $at));
        $this->assertSame(7, $availability->forProduct($product->fresh(), null, $attempt->expires_at));

        app(ReleaseStockReservations::class)->handle($attempt, $at->addMinute());
        $this->assertSame(7, $availability->forProduct($product->fresh(), null, $at->addMinutes(2)));
    }

    public function test_serialized_attempts_cannot_over_reserve_and_lock_products_in_ascending_order(): void
    {
        $firstCustomer = User::factory()->create();
        $secondCustomer = User::factory()->create();
        $lowId = Product::factory()->inStock(5)->create();
        $highId = Product::factory()->inStock(5)->create();
        CartItem::factory()->for($firstCustomer)->for($highId)->create(['quantity' => 4]);
        CartItem::factory()->for($firstCustomer)->for($lowId)->create(['quantity' => 4]);
        CartItem::factory()->for($secondCustomer)->for($lowId)->create(['quantity' => 2]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(CreatePaymentAttempt::class)->handle($firstCustomer, $this->recipient(), (string) Str::uuid());
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $productLock = $queries->first(fn (string $query) => str_contains($query, 'from "products"')
            && str_contains($query, 'order by "id" asc'));
        $this->assertNotNull($productLock);

        try {
            app(CreatePaymentAttempt::class)->handle($secondCustomer, $this->recipient(), (string) Str::uuid());
            $this->fail('Concurrent-equivalent request over-reserved stock.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cart', $exception->errors());
        }
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertSame(4, StockReservation::query()->where('product_id', $lowId->id)->sum('quantity'));
    }

    public function test_failure_on_one_cart_line_rolls_back_attempt_and_every_reservation(): void
    {
        $customer = User::factory()->create();
        $this->addLine($customer, ['sellable_quantity' => 5], 2);
        $this->addLine($customer, ['sellable_quantity' => 1], 2);

        try {
            app(CreatePaymentAttempt::class)->handle($customer, $this->recipient(), (string) Str::uuid());
            $this->fail('An attempt was created when the last cart line lacked stock.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cart', $exception->errors());
        }

        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
    }

    public function test_release_is_idempotent_and_never_changes_product_inventory_or_ledger(): void
    {
        $customer = User::factory()->create();
        $product = $this->addLine($customer, ['sellable_quantity' => 6, 'damaged_quantity' => 2, 'sold_quantity' => 3], 2);
        $attempt = app(CreatePaymentAttempt::class)->handle($customer, $this->recipient(), (string) Str::uuid());
        $before = $product->only(['sellable_quantity', 'damaged_quantity', 'sold_quantity']);
        $release = app(ReleaseStockReservations::class);

        $this->assertSame(1, $release->handle($attempt));
        $releasedAt = $attempt->stockReservations()->firstOrFail()->released_at;
        $this->assertSame(0, $release->handle($attempt));
        $this->assertTrue($releasedAt->equalTo($attempt->stockReservations()->firstOrFail()->released_at));
        $this->assertSame($before, $product->fresh()->only(array_keys($before)));
        $this->assertSame(0, InventoryTransaction::query()->count());
    }

    public function test_expiration_command_is_batched_idempotent_and_ignores_unexpired_attempts(): void
    {
        $now = CarbonImmutable::parse('2026-09-28 12:00:00', 'UTC');
        $this->travelTo($now);
        $expiredAttempts = collect();

        foreach (range(1, 4) as $index) {
            $customer = User::factory()->create();
            $this->addLine($customer);
            $expiredAttempts->push(app(CreatePaymentAttempt::class)->handle(
                $customer,
                $this->recipient(['email' => "receiver{$index}@example.test"]),
                (string) Str::uuid(),
                null,
                $index === 1 ? $now->subMinutes(15) : $now->subMinutes(16),
            ));
        }

        $futureCustomer = User::factory()->create();
        $this->addLine($futureCustomer);
        $future = app(CreatePaymentAttempt::class)->handle(
            $futureCustomer,
            $this->recipient(),
            (string) Str::uuid(),
            null,
            $now,
        );

        $this->artisan('stock-reservations:release-expired', ['--batch' => 2])
            ->expectsOutput('Released 4 reservation(s) across 4 payment attempt(s).')
            ->assertSuccessful();
        $this->assertSame(4, StockReservation::query()->whereNotNull('released_at')->count());
        $this->assertNull($future->stockReservations()->firstOrFail()->released_at);
        $this->assertTrue($expiredAttempts->every(fn (PaymentAttempt $attempt) => $attempt->fresh()->status === PaymentStatus::Unpaid));

        $this->artisan('stock-reservations:release-expired', ['--batch' => 2])
            ->expectsOutput('Released 0 reservation(s) across 0 payment attempt(s).')
            ->assertSuccessful();
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertDatabaseCount('cart_items', 5);
    }

    public function test_attempt_and_reservation_business_snapshots_are_immutable(): void
    {
        $customer = User::factory()->create();
        $this->addLine($customer);
        $attempt = app(CreatePaymentAttempt::class)->handle($customer, $this->recipient(), (string) Str::uuid());
        $reservation = $attempt->stockReservations->first();

        try {
            $attempt->amount_vnd++;
            $attempt->save();
            $this->fail('Payment snapshot was mutable.');
        } catch (\LogicException) {
            $this->assertTrue(true);
        }

        $this->expectException(\LogicException::class);
        $reservation->quantity++;
        $reservation->save();
    }
}
