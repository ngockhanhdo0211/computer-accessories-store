<?php

namespace Tests\Feature;

use App\Actions\CreatePaymentAttempt;
use App\Actions\InitiateVnPayPayment;
use App\Enums\PaymentStatus;
use App\Exceptions\VnPayGatewayException;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\User;
use App\Services\VnPayGateway;
use App\ValueObjects\CheckoutRecipient;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class VnPayPaymentInitiationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureGateway();
    }

    private function configureGateway(): void
    {
        config()->set('services.vnpay', [
            'payment_url' => VnPayGateway::SANDBOX_PAYMENT_URL,
            'terminal_code' => 'ABCDEFGH',
            'hash_secret' => 'test-secret-never-rendered',
            'return_url' => 'https://shop.example.test/checkout/vnpay/return',
            'version' => '2.1.0',
            'timezone' => 'Asia/Ho_Chi_Minh',
        ]);
    }

    private function payload(string $requestKey, array $overrides = []): array
    {
        return array_merge([
            'request_key' => $requestKey,
            'recipient_name' => 'Nguyễn Minh Anh',
            'recipient_email' => 'receiver@example.test',
            'recipient_phone' => '0912345678',
            'province' => 'Hà Nội',
            'district' => 'Cầu Giấy',
            'ward' => 'Dịch Vọng',
            'address_line' => '12 Trần Thái Tông',
            'coupon_code' => '',
        ], $overrides);
    }

    private function customerWithCart(array $product = []): array
    {
        $customer = User::factory()->create();
        $item = Product::factory()->inStock(8)->create(array_merge([
            'price_vnd' => 150_000,
            'sale_price_vnd' => null,
        ], $product));
        CartItem::factory()->for($customer)->for($item)->create(['quantity' => 2]);

        return [$customer, $item];
    }

    private function recipient(): CheckoutRecipient
    {
        return new CheckoutRecipient('Nguyễn Minh Anh', 'receiver@example.test', '0912345678', 'Hà Nội', 'Cầu Giấy', 'Dịch Vọng', '12 Trần Thái Tông');
    }

    public function test_customer_initiates_signed_redirect_without_order_inventory_or_cart_effect(): void
    {
        [$customer, $product] = $this->customerWithCart();
        $before = $product->only(['sellable_quantity', 'damaged_quantity', 'sold_quantity']);
        $key = (string) Str::uuid();

        $response = $this->actingAs($customer)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post(route('checkout.vnpay.initiate'), $this->payload($key, [
                'amount_vnd' => 1,
                'gateway_reference' => 'CLIENT-CONTROLLED',
                'return_url' => 'https://evil.example/return',
                'signature' => 'fake',
            ]));

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith(VnPayGateway::SANDBOX_PAYMENT_URL.'?', $location);
        $this->assertStringContainsString('vnp_Amount=33000000', $location);
        $this->assertStringContainsString('vnp_IpAddr=203.0.113.10', $location);
        $this->assertStringNotContainsString('CLIENT-CONTROLLED', $location);
        $this->assertStringNotContainsString('test-secret-never-rendered', $location);

        $attempt = PaymentAttempt::query()->sole();
        $this->assertMatchesRegularExpression('/^PA[A-F0-9]{32}$/D', $attempt->gateway_reference);
        $this->assertSame('203.0.113.10', $attempt->initiated_ip_address);
        $this->assertSame(PaymentStatus::Unpaid, $attempt->status);
        $this->assertCount(1, $attempt->stockReservations);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertDatabaseCount('cart_items', 1);
        $this->assertSame($before, $product->fresh()->only(array_keys($before)));
    }

    public function test_replay_uses_original_ip_reference_expiry_and_byte_identical_url(): void
    {
        $now = CarbonImmutable::parse('2026-10-02 12:00:00', 'UTC');
        $this->travelTo($now);
        [$customer] = $this->customerWithCart();
        $key = (string) Str::uuid();

        $first = $this->actingAs($customer)->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post(route('checkout.vnpay.initiate'), $this->payload($key));
        $attempt = PaymentAttempt::query()->sole();
        $reference = $attempt->gateway_reference;
        $expiry = $attempt->expires_at;

        $second = $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::10'])
            ->post(route('checkout.vnpay.initiate'), $this->payload($key));

        $this->assertSame($first->headers->get('Location'), $second->headers->get('Location'));
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertDatabaseCount('stock_reservations', 1);
        $this->assertSame($reference, $attempt->fresh()->gateway_reference);
        $this->assertTrue($expiry->equalTo($attempt->fresh()->expires_at));
        $this->assertSame('203.0.113.10', $attempt->fresh()->initiated_ip_address);
    }

    public function test_gateway_reference_collision_retries_without_reclassifying_other_unique_keys(): void
    {
        $collision = Str::uuid();
        $replacement = Str::uuid();
        PaymentAttempt::factory()->create([
            'gateway_reference' => 'PA'.strtoupper(str_replace('-', '', (string) $collision)),
        ]);
        [$customer] = $this->customerWithCart();
        $key = (string) Str::uuid();

        Str::createUuidsUsingSequence([$collision, $replacement]);
        try {
            $initiation = app(InitiateVnPayPayment::class)->handle(
                $customer,
                $this->recipient(),
                $key,
                null,
                '203.0.113.10',
            );
        } finally {
            Str::createUuidsNormally();
        }

        $this->assertSame(
            'PA'.strtoupper(str_replace('-', '', (string) $replacement)),
            $initiation->attempt->gateway_reference,
        );
        $this->assertDatabaseCount('payment_attempts', 2);
    }

    public function test_first_initiation_snapshots_ip_once_for_a_pristine_foundation_attempt(): void
    {
        [$customer] = $this->customerWithCart();
        $key = (string) Str::uuid();
        $attempt = app(CreatePaymentAttempt::class)->handle($customer, $this->recipient(), $key);
        $this->assertNull($attempt->initiated_ip_address);

        $first = app(InitiateVnPayPayment::class)->handle(
            $customer,
            $this->recipient(),
            $key,
            null,
            '203.0.113.10',
        );
        $second = app(InitiateVnPayPayment::class)->handle(
            $customer,
            $this->recipient(),
            $key,
            null,
            '2001:db8::10',
        );

        $this->assertSame('203.0.113.10', $attempt->fresh()->initiated_ip_address);
        $this->assertSame($first->paymentUrl, $second->paymentUrl);
    }

    public function test_missing_or_corrupt_ip_evidence_fails_closed_and_snapshot_rolls_back_on_gateway_failure(): void
    {
        [$corruptCustomer] = $this->customerWithCart();
        $corruptKey = (string) Str::uuid();
        $corrupt = app(CreatePaymentAttempt::class)->handle($corruptCustomer, $this->recipient(), $corruptKey);
        try {
            DB::table('payment_attempts')->where('id', $corrupt->id)->update(['gateway_result_code' => '00']);
            $this->fail('Database accepted partial callback evidence on an unpaid attempt.');
        } catch (QueryException) {
            $this->assertNull($corrupt->fresh()->gateway_result_code);
        }

        [$rollbackCustomer] = $this->customerWithCart();
        $rollbackKey = (string) Str::uuid();
        $rollback = app(CreatePaymentAttempt::class)->handle($rollbackCustomer, $this->recipient(), $rollbackKey);
        $this->app->instance(VnPayGateway::class, new class extends VnPayGateway
        {
            public function buildPaymentUrl(PaymentAttempt $attempt): string
            {
                throw new VnPayGatewayException('Injected failure after IP snapshot.');
            }
        });

        try {
            app(InitiateVnPayPayment::class)->handle($rollbackCustomer, $this->recipient(), $rollbackKey, null, '203.0.113.10');
            $this->fail('Gateway failure did not roll back the first IP snapshot.');
        } catch (VnPayGatewayException) {
            $this->assertNull($rollback->fresh()->initiated_ip_address);
        }
    }

    public function test_replay_rejects_reservation_and_coupon_evidence_that_no_longer_matches_snapshot(): void
    {
        [$stockCustomer] = $this->customerWithCart();
        $stockKey = (string) Str::uuid();
        $stockAttempt = app(CreatePaymentAttempt::class)->handle($stockCustomer, $this->recipient(), $stockKey);
        DB::table('stock_reservations')->where('payment_attempt_id', $stockAttempt->id)->increment('quantity');
        try {
            app(InitiateVnPayPayment::class)->handle($stockCustomer, $this->recipient(), $stockKey, null, '203.0.113.10');
            $this->fail('Mismatched reservation quantity was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cart', $exception->errors());
            $this->assertNull($stockAttempt->fresh()->initiated_ip_address);
        }

        [$couponCustomer] = $this->customerWithCart();
        $coupon = Coupon::factory()->create();
        $couponKey = (string) Str::uuid();
        $couponAttempt = app(CreatePaymentAttempt::class)->handle(
            $couponCustomer,
            $this->recipient(),
            $couponKey,
            $coupon->code,
        );
        DB::table('payment_attempts')->where('id', $couponAttempt->id)->update(['coupon_id' => null]);
        try {
            app(InitiateVnPayPayment::class)->handle($couponCustomer, $this->recipient(), $couponKey, $coupon->code, '203.0.113.10');
            $this->fail('Mismatched Coupon evidence was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('coupon_code', $exception->errors());
        }
    }

    public function test_expired_or_terminal_attempt_cannot_generate_another_url(): void
    {
        $now = CarbonImmutable::parse('2026-10-02 12:00:00', 'UTC');
        $this->travelTo($now);
        [$customer] = $this->customerWithCart();
        $key = (string) Str::uuid();
        app(InitiateVnPayPayment::class)->handle($customer, $this->recipient(), $key, null, '203.0.113.10');

        $this->travelTo($now->addMinutes(15));
        try {
            app(InitiateVnPayPayment::class)->handle($customer, $this->recipient(), $key, null, '2001:db8::10');
            $this->fail('Expired attempt generated a new URL.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('request_key', $exception->errors());
        }

        foreach ([PaymentStatus::Paid, PaymentStatus::Failed, PaymentStatus::Refunded] as $status) {
            [$terminalCustomer] = $this->customerWithCart();
            $terminalKey = (string) Str::uuid();
            app(InitiateVnPayPayment::class)->handle($terminalCustomer, $this->recipient(), $terminalKey, null, '203.0.113.10');
            $evidence = [
                'status' => $status->value,
                'gateway_transaction_id' => $status === PaymentStatus::Failed ? null : 'TXN-'.$terminalCustomer->id,
                'gateway_result_code' => $status === PaymentStatus::Failed ? '24' : '00',
                'gateway_transaction_status' => $status === PaymentStatus::Failed ? '02' : '00',
                'gateway_paid_at' => now(),
                'gateway_bank_code' => 'NCB',
                'callback_fingerprint' => hash('sha256', 'terminal-'.$terminalCustomer->id),
                'verified_at' => now(),
            ];
            DB::table('payment_attempts')->where('request_key', $terminalKey)->update($evidence);
            try {
                app(InitiateVnPayPayment::class)->handle($terminalCustomer, $this->recipient(), $terminalKey, null, '203.0.113.10');
                $this->fail("{$status->value} attempt generated a new URL.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('payment_method', $exception->errors());
            }
        }
    }

    public function test_configuration_and_post_creation_gateway_failures_leave_no_new_evidence(): void
    {
        [$customer] = $this->customerWithCart();
        config()->set('services.vnpay.hash_secret', null);
        try {
            app(InitiateVnPayPayment::class)->handle($customer, $this->recipient(), (string) Str::uuid(), null, '203.0.113.10');
            $this->fail('Missing configuration was accepted.');
        } catch (VnPayGatewayException) {
            $this->assertDatabaseCount('payment_attempts', 0);
        }

        $this->configureGateway();
        $existingKey = (string) Str::uuid();
        $existing = app(InitiateVnPayPayment::class)->handle($customer, $this->recipient(), $existingKey, null, '203.0.113.10')->attempt;
        config()->set('services.vnpay.hash_secret', null);
        try {
            app(InitiateVnPayPayment::class)->handle($customer, $this->recipient(), $existingKey, null, '2001:db8::10');
            $this->fail('Replay accepted invalid current configuration.');
        } catch (VnPayGatewayException) {
            $this->assertDatabaseHas('payment_attempts', ['id' => $existing->id, 'initiated_ip_address' => '203.0.113.10']);
            $this->assertNull($existing->stockReservations()->firstOrFail()->released_at);
        }

        $this->configureGateway();
        [$couponCustomer] = $this->customerWithCart();
        $coupon = Coupon::factory()->create();
        $this->app->instance(VnPayGateway::class, new class extends VnPayGateway
        {
            public function buildPaymentUrl(PaymentAttempt $attempt): string
            {
                throw new VnPayGatewayException('Injected failure after attempt creation.');
            }
        });

        try {
            app(InitiateVnPayPayment::class)->handle($couponCustomer, $this->recipient(), (string) Str::uuid(), $coupon->code, '203.0.113.10');
            $this->fail('Gateway failure did not roll back the attempt.');
        } catch (VnPayGatewayException) {
            $this->assertDatabaseCount('payment_attempts', 1);
            $this->assertDatabaseCount('stock_reservations', 1);
            $this->assertDatabaseCount('coupon_usages', 0);
        }
    }

    public function test_checkout_renders_separate_cod_and_vnpay_forms_keys_and_configuration_state(): void
    {
        [$customer] = $this->customerWithCart();
        $codKey = (string) Str::uuid();
        $response = $this->actingAs($customer)->post(route('checkout.quote'), $this->payload($codKey));

        $response->assertOk()
            ->assertSee('action="'.route('checkout.cod.store').'"', false)
            ->assertSee('action="'.route('checkout.vnpay.initiate').'"', false)
            ->assertSee('Thanh toán khi nhận hàng (COD)')
            ->assertSee('Chuyển sang cổng VNPay')
            ->assertDontSee('Dịch vụ VNPay tạm thời chưa khả dụng');
        $this->assertSame($codKey, $response->viewData('requestKey'));
        $this->assertNotSame($codKey, $response->viewData('vnpayRequestKey'));

        config()->set('services.vnpay.hash_secret', null);
        $unavailable = $this->post(route('checkout.quote'), $this->payload((string) Str::uuid()));
        $unavailable->assertOk()
            ->assertSee('Dịch vụ VNPay tạm thời chưa khả dụng')
            ->assertSee('disabled', false)
            ->assertDontSee('test-secret-never-rendered');
    }

    public function test_routes_authorization_validation_return_page_and_public_ipn_are_safe(): void
    {
        $key = (string) Str::uuid();
        $this->post(route('checkout.vnpay.initiate'), $this->payload($key))->assertRedirect(route('login'));
        foreach ([User::factory()->employee()->create(), User::factory()->admin()->create()] as $actor) {
            $this->actingAs($actor)->post(route('checkout.vnpay.initiate'), $this->payload($key))->assertForbidden();
        }
        foreach ([User::factory()->locked()->create(), User::factory()->inactive()->create()] as $actor) {
            $this->actingAs($actor)->post(route('checkout.vnpay.initiate'), $this->payload($key))
                ->assertRedirect(route('login'));
            $this->assertGuest();
        }

        [$customer] = $this->customerWithCart();
        $this->actingAs($customer)->post(route('checkout.vnpay.initiate'), $this->payload('bad-key'))
            ->assertRedirect(route('checkout.show'))->assertSessionHasErrors('request_key', null, 'vnpay')
            ->assertSessionMissing('_old_input');

        $before = DB::table('payment_attempts')->count();
        $this->get(route('checkout.vnpay.return', ['vnp_ResponseCode' => '00', 'vnp_SecureHash' => 'forged']))
            ->assertOk()->assertSee('Kết quả thanh toán đang được hệ thống xác minh.')
            ->assertDontSee('forged')->assertDontSee('thanh toán thành công');
        $this->assertSame($before, DB::table('payment_attempts')->count());
        $this->get(route('checkout.vnpay.return').'?vnp_ResponseCode%5B%5D=00&noise='.str_repeat('x', 4000))
            ->assertOk()->assertDontSee(str_repeat('x', 100));
        $this->assertSame($before, DB::table('payment_attempts')->count());

        $routes = collect(app('router')->getRoutes()->getRoutes());
        $initiate = $routes->firstWhere('action.as', 'checkout.vnpay.initiate');
        $return = $routes->firstWhere('action.as', 'checkout.vnpay.return');
        $ipn = $routes->firstWhere('action.as', 'checkout.vnpay.ipn');
        $this->assertSame(['POST'], $initiate->methods());
        $this->assertSame(['GET', 'HEAD'], $return->methods());
        $this->assertSame(['GET', 'HEAD'], $ipn->methods());
        $this->assertSame(['web'], $ipn->gatherMiddleware());
    }
}
