<?php

namespace Tests\Feature;

use App\Actions\InitiateVnPayPayment;
use App\Actions\ProcessVnPayIpn;
use App\Actions\ReleaseStockReservations;
use App\Enums\CouponScope;
use App\Enums\PaymentStatus;
use App\Enums\RefundReason;
use App\Enums\RefundStatus;
use App\Models\AuditLog;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\OrderItem;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\User;
use App\Services\VnPayCallbackParser;
use App\Services\VnPayGateway;
use App\ValueObjects\CheckoutRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class VnPayCallbackFinalizationTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'callback-test-secret-never-rendered';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.vnpay', [
            'payment_url' => VnPayGateway::SANDBOX_PAYMENT_URL,
            'terminal_code' => 'ABCDEFGH',
            'hash_secret' => $this->secret,
            'return_url' => 'https://shop.example.test/checkout/vnpay/return',
            'version' => '2.1.0',
            'timezone' => 'Asia/Ho_Chi_Minh',
        ]);
    }

    public function test_success_callback_creates_order_consumes_resources_inventory_and_exact_cart(): void
    {
        [$attempt, $product, $cart] = $this->attempt();
        $beforeStock = $product->sellable_quantity;

        $response = $this->get($this->callbackUrl($attempt));

        $response->assertOk()->assertExactJson(['RspCode' => '00', 'Message' => 'Confirm Success']);
        $attempt->refresh();
        $this->assertSame(PaymentStatus::Paid, $attempt->status);
        $this->assertSame('123456789', $attempt->gateway_transaction_id);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $attempt->callback_fingerprint);
        $order = $attempt->order()->with('items')->sole();
        $this->assertSame($attempt->amount_vnd, $order->total_vnd);
        $this->assertSame($attempt->pricing_snapshot_json['item_discount_vnd'], $order->items->sum('discount_vnd'));
        $this->assertSame($beforeStock - 2, $product->fresh()->sellable_quantity);
        $this->assertDatabaseHas('inventory_transactions', ['order_item_id' => $order->items->sole()->id, 'sellable_delta' => -2]);
        $this->assertNotNull($attempt->stockReservations()->sole()->consumed_at);
        $this->assertDatabaseMissing('cart_items', ['id' => $cart->id]);
        $this->assertDatabaseCount('refunds', 0);

        $duplicate = $this->get($this->callbackUrl($attempt));
        $duplicate->assertExactJson(['RspCode' => '02', 'Message' => 'Order already confirmed']);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('inventory_transactions', 1);
    }

    public function test_failed_callback_releases_resources_without_order_refund_inventory_or_cart_change(): void
    {
        [$attempt, $product, $cart] = $this->attempt();
        $before = $product->sellable_quantity;

        $this->get($this->callbackUrl($attempt, ['vnp_ResponseCode' => '24', 'vnp_TransactionStatus' => '02', 'vnp_TransactionNo' => '0']))
            ->assertExactJson(['RspCode' => '00', 'Message' => 'Confirm Success']);

        $this->assertSame(PaymentStatus::Failed, $attempt->fresh()->status);
        $this->assertNull($attempt->fresh()->gateway_transaction_id);
        $this->assertNotNull($attempt->stockReservations()->sole()->released_at);
        $this->assertSame($before, $product->fresh()->sellable_quantity);
        $this->assertDatabaseHas('cart_items', ['id' => $cart->id]);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('refunds', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);

        $failed = ['vnp_ResponseCode' => '24', 'vnp_TransactionStatus' => '02', 'vnp_TransactionNo' => '0'];
        $this->get($this->callbackUrl($attempt, $failed))->assertJson(['RspCode' => '02']);
        $this->get($this->callbackUrl($attempt))->assertJson(['RspCode' => '99']);
        $this->assertSame(PaymentStatus::Failed, $attempt->fresh()->status);
    }

    public function test_late_callback_with_insufficient_stock_creates_full_pending_refund_exactly_once(): void
    {
        [$attempt, $product, $cart] = $this->attempt();
        $expiredAt = now()->subMinute();
        DB::table('payment_attempts')->where('id', $attempt->id)->update(['expires_at' => $expiredAt]);
        DB::table('stock_reservations')->where('payment_attempt_id', $attempt->id)->update([
            'expires_at' => $expiredAt, 'released_at' => now()->subSeconds(30),
        ]);
        $product->forceFill(['sellable_quantity' => 0])->save();

        $this->get($this->callbackUrl($attempt))->assertJson(['RspCode' => '00']);
        $this->assertSame(PaymentStatus::Paid, $attempt->fresh()->status);
        $refund = $attempt->refund()->sole();
        $this->assertSame(RefundStatus::Pending, $refund->status);
        $this->assertSame(RefundReason::StockUnavailable, $refund->reason);
        $this->assertSame($attempt->amount_vnd, $refund->amount_vnd);
        $this->assertNull($refund->order_id);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('cart_items', ['id' => $cart->id]);

        $this->get($this->callbackUrl($attempt))->assertJson(['RspCode' => '02']);
        $this->assertDatabaseCount('refunds', 1);
    }

    public function test_changed_cart_is_preserved_and_conflicting_duplicate_returns_99(): void
    {
        [$attempt, , $cart] = $this->attempt();
        $cart->update(['quantity' => 3]);

        $this->get($this->callbackUrl($attempt))->assertJson(['RspCode' => '00']);
        $this->assertDatabaseHas('cart_items', ['id' => $cart->id, 'quantity' => 3]);

        $this->get($this->callbackUrl($attempt, ['vnp_TransactionNo' => '999999999']))
            ->assertJson(['RspCode' => '99']);
        $this->assertSame('123456789', $attempt->fresh()->gateway_transaction_id);
        $this->assertDatabaseHas('audit_logs', [
            'subject_id' => $attempt->id, 'action' => 'vnpay_callback_conflict',
        ]);
    }

    public function test_signature_reference_amount_and_malformed_input_use_official_codes_without_mutation(): void
    {
        [$attempt] = $this->attempt();

        $this->get($this->callbackUrl($attempt, [], 'bad-signature'))->assertJson(['RspCode' => '97']);
        $this->get($this->callbackUrl($attempt, ['vnp_TxnRef' => 'PA'.str_repeat('F', 32)], 'bad-signature'))
            ->assertJson(['RspCode' => '97']);
        $this->get($this->callbackUrl($attempt, ['vnp_TxnRef' => 'PA'.str_repeat('F', 32)]))->assertJson(['RspCode' => '01']);
        $this->get($this->callbackUrl($attempt, ['vnp_Amount' => (string) (($attempt->amount_vnd + 1) * 100)]))->assertJson(['RspCode' => '04']);
        $this->get($this->callbackUrl($attempt, ['vnp_TmnCode' => 'WRONGMER']))->assertJson(['RspCode' => '99']);
        $this->get(route('checkout.vnpay.ipn').'?vnp_TxnRef%5B%5D=x&vnp_TxnRef=y')->assertJson(['RspCode' => '99']);
        $this->assertSame(PaymentStatus::Unpaid, $attempt->fresh()->status);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_raw_query_parser_rejects_ambiguous_control_and_oversized_inputs(): void
    {
        [$attempt] = $this->attempt();
        $valid = $this->signedRaw($this->callbackParameters($attempt));
        $signature = str($valid)->afterLast('vnp_SecureHash=')->toString();
        $withoutSignature = str($valid)->beforeLast('&vnp_SecureHash=')->toString();
        $action = app(ProcessVnPayIpn::class);

        foreach ([
            'vnp_Amount=1&'.$valid,
            $valid.'&vnp_Amount=1',
            $valid.'&vnp_SecureHash='.$signature,
            $valid.'&%76np_TxnRef='.$attempt->gateway_reference,
            $valid.'&vnp_TxnRef%5B%5D=x',
            $valid.'&vnp_TxnRef%5Bchild%5D=x',
            $withoutSignature.'&vnp_SecureHash='.$signature.'%',
            $withoutSignature.'&vnp_SecureHash='.$signature.'%0',
            $withoutSignature.'&vnp_SecureHash='.$signature.'%GG',
            str_replace('Thanh+toan', 'Thanh%00toan', $valid),
            str_replace('Thanh+toan', 'Thanh%0D%0Atoan', $valid),
            $this->signedRaw(array_merge($this->callbackParameters($attempt), ['vnp_Unknown' => 'signed-but-unsupported'])),
            $this->signedRaw($this->callbackParameters($attempt, ['vnp_BankCode' => ''])),
            $this->signedRaw($this->callbackParameters($attempt, ['vnp_OrderInfo' => str_repeat('a', 300)])),
            str_replace('vnp_TxnRef=', 'VNP_TxnRef=', $valid),
            'x='.str_repeat('a', 4096),
        ] as $raw) {
            $this->assertSame('99', $action->handle($raw)->code);
        }

        $this->assertSame('97', $action->handle($withoutSignature)->code);
        $this->assertSame('97', $action->handle($withoutSignature.'&vnp_SecureHash=')->code);
        $this->assertSame(PaymentStatus::Unpaid, $attempt->fresh()->status);
    }

    public function test_parser_canonicalization_accepts_equivalent_encoding_unicode_reordering_and_equals_in_value(): void
    {
        [$attempt] = $this->attempt();
        $parser = app(VnPayCallbackParser::class);

        $spaceRaw = $this->signedRaw($this->callbackParameters($attempt));
        $percentSpaceRaw = str_replace('+', '%20', $spaceRaw);
        $this->assertSame($parser->parse($spaceRaw)->fingerprint(), $parser->parse($percentSpaceRaw)->fingerprint());

        $unicodeRaw = $this->signedRaw($this->callbackParameters($attempt, [
            'vnp_OrderInfo' => 'Thanh toán đơn hàng Việt Nam',
        ]));
        $reordered = implode('&', array_reverse(explode('&', $unicodeRaw)));
        $this->assertSame($parser->parse($unicodeRaw)->fingerprint(), $parser->parse($reordered)->fingerprint());
        $changed = $this->signedRaw($this->callbackParameters($attempt, ['vnp_OrderInfo' => 'Nội dung nghiệp vụ khác']));
        $this->assertNotSame($parser->parse($unicodeRaw)->fingerprint(), $parser->parse($changed)->fingerprint());

        $equalsRaw = $this->signedRaw($this->callbackParameters($attempt, ['vnp_OrderInfo' => 'Thanh=toan']));
        $equalsRaw = str_replace('vnp_OrderInfo=Thanh%3Dtoan', 'vnp_OrderInfo=Thanh=toan', $equalsRaw);
        $this->assertSame('Thanh=toan', $parser->parse($equalsRaw)->orderInfo);

        $leadingZeroRaw = $this->signedRaw($this->callbackParameters($attempt, ['vnp_TransactionNo' => '000123']));
        $this->assertSame('000123', $parser->parse($leadingZeroRaw)->transactionId);
    }

    public function test_ipn_route_is_get_only_json_and_never_exposes_secret(): void
    {
        [$attempt] = $this->attempt();

        $response = $this->get($this->callbackUrl($attempt, [], 'bad-signature'));
        $response->assertOk()->assertHeader('content-type', 'application/json')
            ->assertExactJson(['RspCode' => '97', 'Message' => 'Invalid signature'])
            ->assertDontSee($this->secret);
        $this->post(route('checkout.vnpay.ipn'))->assertMethodNotAllowed();
    }

    public function test_historical_snapshot_without_discount_creates_order_and_preserves_cart(): void
    {
        [$attempt, , $cart] = $this->attempt();
        $this->historicalize($attempt);

        $this->get($this->callbackUrl($attempt))->assertJson(['RspCode' => '00']);

        $order = $attempt->fresh()->order()->with('items')->sole();
        $this->assertSame(0, $order->items->sole()->discount_vnd);
        $this->assertDatabaseHas('cart_items', ['id' => $cart->id]);
    }

    public function test_historical_single_line_discount_is_inferred_without_reading_coupon_targets(): void
    {
        $coupon = Coupon::factory()->fixed(30_000)->create();
        [$attempt, , $cart] = $this->attempt($coupon);
        $this->historicalize($attempt);

        $this->get($this->callbackUrl($attempt))->assertJson(['RspCode' => '00']);

        $item = $attempt->fresh()->order()->sole()->items()->sole();
        $this->assertSame(30_000, $item->discount_vnd);
        $this->assertDatabaseHas('cart_items', ['id' => $cart->id]);
    }

    public function test_historical_multi_line_positive_discount_creates_snapshot_incomplete_refund(): void
    {
        $coupon = Coupon::factory()->fixed(30_000)->create();
        [$attempt, , $carts] = $this->attemptWithLines([100_000, 200_000], $coupon);
        $this->historicalize($attempt);

        $this->get($this->callbackUrl($attempt))->assertJson(['RspCode' => '00']);

        $refund = $attempt->fresh()->refund()->sole();
        $this->assertSame(RefundReason::SnapshotIncomplete, $refund->reason);
        $this->assertDatabaseCount('orders', 0);
        foreach ($carts as $cart) {
            $this->assertDatabaseHas('cart_items', ['id' => $cart->id]);
        }
        $this->get($this->callbackUrl($attempt))->assertJson(['RspCode' => '02']);
        $this->assertDatabaseCount('refunds', 1);
    }

    public function test_new_snapshot_uses_immutable_line_allocation_after_coupon_targets_change(): void
    {
        $coupon = Coupon::factory()->create(['scope' => CouponScope::Product, 'value' => 10]);
        [$attempt, $products] = $this->attemptWithLines([100_000, 200_000], $coupon, true);
        DB::table('coupon_products')->where('coupon_id', $coupon->id)->delete();
        DB::table('coupon_products')->insert(['coupon_id' => $coupon->id, 'product_id' => $products[1]->id]);

        $expected = collect($attempt->items_snapshot_json)->mapWithKeys(
            fn (array $line): array => [$line['product_id'] => $line['discount_vnd']],
        )->all();
        $this->get($this->callbackUrl($attempt))->assertJson(['RspCode' => '00']);

        $actual = $attempt->fresh()->order()->sole()->items()->pluck('discount_vnd', 'product_id')->all();
        $this->assertSame($expected, $actual);
        $this->assertGreaterThan(0, $actual[$products[0]->id]);
        $this->assertSame(0, $actual[$products[1]->id]);
    }

    public function test_new_snapshot_corruption_returns_99_without_paid_state_or_refund(): void
    {
        [$attempt] = $this->attempt();
        $items = $attempt->items_snapshot_json;
        $items[0]['line_total_vnd']++;
        DB::table('payment_attempts')->where('id', $attempt->id)->update([
            'items_snapshot_json' => json_encode($items, JSON_THROW_ON_ERROR),
        ]);

        $this->get($this->callbackUrl($attempt))->assertJson(['RspCode' => '99']);
        $this->assertSame(PaymentStatus::Unpaid, $attempt->fresh()->status);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_snapshot_type_shape_duplicate_cart_identity_and_overflow_corruption_fail_closed(): void
    {
        $mutations = [
            function (array $items): array {
                $items[0]['quantity'] = (string) $items[0]['quantity'];

                return $items;
            },
            function (array $items): array {
                $items[0]['discount_vnd'] = false;

                return $items;
            },
            function (array $items): array {
                unset($items[0]['sku']);

                return $items;
            },
            function (array $items): array {
                $items[0]['cart_item_updated_at'] = '2026-99-99T25:61:61.000000Z';

                return $items;
            },
            function (array $items): array {
                $items[0]['quantity'] = PHP_INT_MAX;
                $items[0]['unit_price_vnd'] = 2;

                return $items;
            },
        ];

        foreach ($mutations as $mutation) {
            [$attempt] = $this->attempt();
            DB::table('payment_attempts')->where('id', $attempt->id)->update([
                'items_snapshot_json' => json_encode($mutation($attempt->items_snapshot_json), JSON_THROW_ON_ERROR),
            ]);

            $this->get($this->callbackUrl($attempt))->assertJson(['RspCode' => '99']);
            $this->assertSame(PaymentStatus::Unpaid, $attempt->fresh()->status);
            $this->assertNull($attempt->order);
            $this->assertNull($attempt->refund);
        }

        [$attempt] = $this->attemptWithLines([100_000, 200_000]);
        $items = $attempt->items_snapshot_json;
        $items[1]['cart_item_id'] = $items[0]['cart_item_id'];
        DB::table('payment_attempts')->where('id', $attempt->id)->update([
            'items_snapshot_json' => json_encode($items, JSON_THROW_ON_ERROR),
        ]);
        $this->get($this->callbackUrl($attempt))->assertJson(['RspCode' => '99']);
        $this->assertSame(PaymentStatus::Unpaid, $attempt->fresh()->status);
    }

    public function test_order_item_and_cart_cleanup_failures_roll_back_every_finalization_effect(): void
    {
        [$itemAttempt, $itemProduct] = $this->attempt();
        $itemStock = $itemProduct->sellable_quantity;
        OrderItem::creating(fn () => throw new \RuntimeException('Injected Order Item failure.'));

        $this->get($this->callbackUrl($itemAttempt))->assertJson(['RspCode' => '99']);
        $this->assertSame(PaymentStatus::Unpaid, $itemAttempt->fresh()->status);
        $this->assertSame($itemStock, $itemProduct->fresh()->sellable_quantity);
        $this->assertNull($itemAttempt->order);

        OrderItem::flushEventListeners();
        [$cartAttempt, $cartProduct, $cart] = $this->attempt();
        $cartStock = $cartProduct->sellable_quantity;
        CartItem::deleting(fn () => throw new \RuntimeException('Injected Cart cleanup failure.'));

        $this->get($this->callbackUrl($cartAttempt, ['vnp_TransactionNo' => '123456790']))->assertJson(['RspCode' => '99']);
        $this->assertSame(PaymentStatus::Unpaid, $cartAttempt->fresh()->status);
        $this->assertSame($cartStock, $cartProduct->fresh()->sellable_quantity);
        $this->assertNull($cartAttempt->order);
        $this->assertDatabaseHas('cart_items', ['id' => $cart->id]);
    }

    public function test_late_callback_with_stock_and_coupon_capacity_creates_order_without_recreating_reservation(): void
    {
        $coupon = Coupon::factory()->fixed(10_000)->create(['max_uses' => 1]);
        [$attempt, $product] = $this->attempt($coupon);
        app(ReleaseStockReservations::class)->handle($attempt, now()->addMinutes(16));
        $releasedAt = $attempt->stockReservations()->sole()->released_at;

        $this->get($this->callbackUrl($attempt))->assertJson(['RspCode' => '00']);

        $attempt->refresh();
        $this->assertTrue($attempt->late_callback_exception);
        $this->assertNotNull($attempt->order);
        $this->assertDatabaseCount('stock_reservations', 1);
        $this->assertTrue($releasedAt->equalTo($attempt->stockReservations()->sole()->released_at));
        $this->assertNull($attempt->stockReservations()->sole()->consumed_at);
        $this->assertTrue($attempt->couponUsage->late_callback_exception);
        $this->assertSame(6, $product->fresh()->sellable_quantity);
    }

    public function test_late_callback_without_coupon_capacity_creates_coupon_refund(): void
    {
        $coupon = Coupon::factory()->fixed(10_000)->create(['max_uses' => 1]);
        [$attempt] = $this->attempt($coupon);
        app(ReleaseStockReservations::class)->handle($attempt, now()->addMinutes(16));

        $other = User::factory()->create();
        $otherProduct = Product::factory()->inStock(5)->create(['price_vnd' => 100_000, 'sale_price_vnd' => null]);
        CartItem::factory()->for($other)->for($otherProduct)->create(['quantity' => 1]);
        app(InitiateVnPayPayment::class)->handle(
            $other,
            new CheckoutRecipient('Other', 'other@example.test', '0912345678', 'Ha Noi', 'D', 'W', 'Address'),
            (string) Str::uuid(), $coupon->code, '203.0.113.11',
        );

        $this->get($this->callbackUrl($attempt))->assertJson(['RspCode' => '00']);

        $this->assertSame(RefundReason::CouponCapacityUnavailable, $attempt->fresh()->refund()->sole()->reason);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_transaction_number_is_unique_and_second_attempt_rolls_back(): void
    {
        [$first] = $this->attempt();
        [$second, $secondProduct] = $this->attempt();
        $before = $secondProduct->sellable_quantity;
        $this->get($this->callbackUrl($first))->assertJson(['RspCode' => '00']);

        $this->get($this->callbackUrl($second))->assertJson(['RspCode' => '99']);

        $this->assertSame(PaymentStatus::Unpaid, $second->fresh()->status);
        $this->assertSame($before, $secondProduct->fresh()->sellable_quantity);
        $this->assertNull($second->order);
        $this->assertNull($second->refund);
    }

    public function test_audit_failure_rolls_back_order_and_refund_outcomes(): void
    {
        [$orderAttempt, $orderProduct] = $this->attempt();
        $orderStock = $orderProduct->sellable_quantity;
        AuditLog::creating(fn () => throw new \RuntimeException('Injected audit failure.'));

        $this->get($this->callbackUrl($orderAttempt))->assertJson(['RspCode' => '99']);
        $this->assertSame(PaymentStatus::Unpaid, $orderAttempt->fresh()->status);
        $this->assertSame($orderStock, $orderProduct->fresh()->sellable_quantity);
        $this->assertDatabaseCount('orders', 0);

        [$refundAttempt, $refundProduct] = $this->attempt();
        $expiredAt = now()->subMinute();
        DB::table('payment_attempts')->where('id', $refundAttempt->id)->update(['expires_at' => $expiredAt]);
        DB::table('stock_reservations')->where('payment_attempt_id', $refundAttempt->id)->update([
            'expires_at' => $expiredAt, 'released_at' => now()->subSeconds(30),
        ]);
        $refundProduct->forceFill(['sellable_quantity' => 0])->save();
        $this->get($this->callbackUrl($refundAttempt, ['vnp_TransactionNo' => '123456790']))
            ->assertJson(['RspCode' => '99']);
        $this->assertSame(PaymentStatus::Unpaid, $refundAttempt->fresh()->status);
        $this->assertDatabaseCount('refunds', 0);
    }

    private function attempt(?Coupon $coupon = null): array
    {
        $customer = User::factory()->create();
        $product = Product::factory()->inStock(8)->create(['price_vnd' => 150_000, 'sale_price_vnd' => null]);
        $cart = CartItem::factory()->for($customer)->for($product)->create(['quantity' => 2]);
        $attempt = app(InitiateVnPayPayment::class)->handle(
            $customer,
            new CheckoutRecipient('Nguyen Minh Anh', 'receiver@example.test', '0912345678', 'Ha Noi', 'Cau Giay', 'Dich Vong', '12 Tran Thai Tong'),
            (string) Str::uuid(), $coupon?->code, '203.0.113.10',
        )->attempt;

        return [$attempt, $product, $cart];
    }

    private function attemptWithLines(array $prices, ?Coupon $coupon = null, bool $attachFirstTarget = false): array
    {
        $customer = User::factory()->create();
        $products = [];
        $carts = [];
        foreach ($prices as $price) {
            $product = Product::factory()->inStock(8)->create(['price_vnd' => $price, 'sale_price_vnd' => null]);
            $products[] = $product;
            $carts[] = CartItem::factory()->for($customer)->for($product)->create(['quantity' => 1]);
        }
        if ($attachFirstTarget && $coupon !== null) {
            DB::table('coupon_products')->insert(['coupon_id' => $coupon->id, 'product_id' => $products[0]->id]);
        }
        $attempt = app(InitiateVnPayPayment::class)->handle(
            $customer,
            new CheckoutRecipient('Nguyen Minh Anh', 'receiver@example.test', '0912345678', 'Ha Noi', 'Cau Giay', 'Dich Vong', '12 Tran Thai Tong'),
            (string) Str::uuid(), $coupon?->code, '203.0.113.10',
        )->attempt;

        return [$attempt, $products, $carts];
    }

    private function historicalize(PaymentAttempt $attempt): void
    {
        $legacy = array_map(function (array $line): array {
            return [
                'product_id' => $line['product_id'], 'category_id' => $line['category_id'],
                'brand_id' => $line['brand_id'], 'sku' => $line['sku'],
                'product_name' => $line['product_name'], 'quantity' => $line['quantity'],
                'unit_price_vnd' => $line['unit_price_vnd'], 'line_subtotal_vnd' => $line['subtotal_vnd'],
            ];
        }, $attempt->items_snapshot_json);
        DB::table('payment_attempts')->where('id', $attempt->id)->update([
            'items_snapshot_json' => json_encode($legacy, JSON_THROW_ON_ERROR),
        ]);
        $attempt->refresh();
    }

    private function callbackUrl(PaymentAttempt $attempt, array $overrides = [], ?string $forcedSignature = null): string
    {
        $raw = $this->signedRaw($this->callbackParameters($attempt, $overrides), $forcedSignature);

        return route('checkout.vnpay.ipn').'?'.$raw;
    }

    private function callbackParameters(PaymentAttempt $attempt, array $overrides = []): array
    {
        return array_merge([
            'vnp_TmnCode' => 'ABCDEFGH',
            'vnp_Amount' => (string) ($attempt->amount_vnd * 100),
            'vnp_BankCode' => 'NCB',
            'vnp_BankTranNo' => 'VNP123456',
            'vnp_CardType' => 'ATM',
            'vnp_OrderInfo' => 'Thanh toan '.$attempt->gateway_reference,
            'vnp_PayDate' => '20261002210000',
            'vnp_ResponseCode' => '00',
            'vnp_TxnRef' => $attempt->gateway_reference,
            'vnp_TransactionNo' => '123456789',
            'vnp_TransactionStatus' => '00',
        ], $overrides);
    }

    private function signedRaw(array $parameters, ?string $forcedSignature = null): string
    {
        $canonical = app(VnPayGateway::class)->canonicalQuery($parameters);
        $signature = $forcedSignature ?? hash_hmac('sha512', $canonical, $this->secret);

        return $canonical.'&vnp_SecureHash='.$signature;
    }
}
