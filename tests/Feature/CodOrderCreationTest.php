<?php

namespace Tests\Feature;

use App\Actions\BuildCheckoutQuote;
use App\Actions\BuildCodOrderFingerprint;
use App\Actions\CreateCodOrder;
use App\Enums\CouponScope;
use App\Enums\CouponUsageStatus;
use App\Enums\InventoryTransactionType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShippingRate;
use App\Models\StockReservation;
use App\Models\User;
use App\ValueObjects\CheckoutRecipient;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CodOrderCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_creates_atomic_cod_order_with_snapshots_history_ledger_and_cart_cleanup(): void
    {
        $customer = User::factory()->create();
        $first = $this->addLine($customer, ['name' => 'Bàn phím <Pro>', 'sku' => 'KEY-1', 'price_vnd' => 101_000], 2);
        $second = $this->addLine($customer, ['name' => 'Chuột', 'sku' => 'MOUSE-1', 'price_vnd' => 99_000], 1);
        $beforeSecond = $second->only(['damaged_quantity', 'sold_quantity']);

        $response = $this->quote($customer);
        $requestKey = session('checkout.cod_confirmation.request_key');
        $response->assertSee('Đặt hàng COD')
            ->assertSee('name="_token"', false)
            ->assertSee('name="request_key"', false);

        $this->post(route('checkout.cod.store'), $this->payload(['request_key' => $requestKey]))
            ->assertRedirect();

        $order = Order::query()->sole();
        $this->assertSame($customer->id, $order->user_id);
        $this->assertSame(PaymentMethod::CashOnDelivery, $order->payment_method);
        $this->assertSame(PaymentStatus::Unpaid, $order->payment_status);
        $this->assertNull($order->payment_attempt_id);
        $this->assertSame(301_000, $order->items_subtotal_vnd);
        $this->assertSame(331_000, $order->total_vnd);
        $this->assertCount(2, $order->items);
        $this->assertSame([$first->id, $second->id], $order->items->pluck('product_id')->all());
        $this->assertSame(1, $order->statusHistories()->count());
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id, 'from_status' => null, 'to_status' => 'da_dat', 'actor_id' => $customer->id,
        ]);
        $this->assertDatabaseCount('cart_items', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->assertDatabaseCount('coupon_usages', 0);
        $this->assertDatabaseCount('inventory_transactions', 2);
        $this->assertSame([-2, -1], InventoryTransaction::query()->orderBy('product_id')->pluck('sellable_delta')->all());
        $this->assertTrue(InventoryTransaction::query()->get()->every(
            fn (InventoryTransaction $ledger): bool => $ledger->type === InventoryTransactionType::Sale && $ledger->order_item_id !== null,
        ));
        $ledger = InventoryTransaction::query()->firstOrFail();
        $duplicate = $ledger->getAttributes();
        unset($duplicate['id']);
        try {
            DB::table('inventory_transactions')->insert($duplicate);
            $this->fail('The inventory source unique boundary accepted a duplicate sale ledger.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
        $this->assertDatabaseCount('inventory_transactions', 2);
        $this->assertSame(8, $first->refresh()->sellable_quantity);
        $this->assertSame($beforeSecond, $second->refresh()->only(array_keys($beforeSecond)));
    }

    public function test_cod_coupon_is_consumed_directly_and_largest_remainder_is_deterministic(): void
    {
        $customer = User::factory()->create();
        $first = $this->addLine($customer, ['price_vnd' => 1], 1);
        $second = $this->addLine($customer, ['price_vnd' => 1], 1);
        $third = $this->addLine($customer, ['price_vnd' => 1], 1);
        $coupon = Coupon::factory()->fixed(2)->create(['code' => 'TWO']);

        $requestKey = $this->quote($customer, ['coupon_code' => $coupon->code])
            ->viewData('requestKey');
        $this->post(route('checkout.cod.store'), $this->payload([
            'request_key' => $requestKey,
            'coupon_code' => $coupon->code,
        ]))->assertRedirect();

        $order = Order::query()->sole();
        $this->assertSame([$first->id, $second->id, $third->id], $order->items->pluck('product_id')->all());
        $this->assertSame([1, 1, 0], $order->items->pluck('discount_vnd')->all());
        $this->assertSame(2, $order->item_discount_vnd);
        $usage = CouponUsage::query()->sole();
        $this->assertSame(CouponUsageStatus::Consumed, $usage->status);
        $this->assertNull($usage->payment_attempt_id);
        $this->assertSame($order->id, $usage->order_id);
        $this->assertSame($customer->id, $usage->customer_id);
    }

    public function test_cod_enforces_per_customer_coupon_capacity_and_allows_unlimited_usage(): void
    {
        $customer = User::factory()->create();
        $limited = Coupon::factory()->create(['max_uses_per_user' => 1]);
        $this->addLine($customer);
        $key = $this->quote($customer, ['coupon_code' => $limited->code])->viewData('requestKey');
        $this->post(route('checkout.cod.store'), $this->payload(['request_key' => $key, 'coupon_code' => $limited->code]))->assertRedirect();

        $secondProduct = $this->addLine($customer);
        $key = $this->quote($customer, ['coupon_code' => $limited->code])->viewData('requestKey');
        $this->post(route('checkout.cod.store'), $this->payload(['request_key' => $key, 'coupon_code' => $limited->code]))
            ->assertSessionHasErrors('coupon_code');
        $this->assertDatabaseHas('cart_items', ['user_id' => $customer->id, 'product_id' => $secondProduct->id]);

        $unlimitedCustomer = User::factory()->create();
        $unlimited = Coupon::factory()->create();
        foreach ([1, 2] as $iteration) {
            $this->addLine($unlimitedCustomer, ['sku' => 'UNLIMITED-'.$iteration]);
            $key = $this->quote($unlimitedCustomer, ['coupon_code' => $unlimited->code])->viewData('requestKey');
            $this->post(route('checkout.cod.store'), $this->payload(['request_key' => $key, 'coupon_code' => $unlimited->code]))->assertRedirect();
        }
        $this->assertSame(2, $unlimited->usages()->count());
    }

    public function test_percent_fixed_free_shipping_and_scoped_coupons_persist_server_totals(): void
    {
        foreach (['percent', 'fixed', 'free_shipping', 'product', 'category', 'brand'] as $case) {
            $customer = User::factory()->create();
            $eligible = $this->addLine($customer, ['price_vnd' => 100_000]);
            $this->addLine($customer, ['price_vnd' => 50_000]);
            $coupon = match ($case) {
                'fixed' => Coupon::factory()->fixed(30_000)->create(),
                'free_shipping' => Coupon::factory()->freeShipping()->create(),
                'product' => Coupon::factory()->create(['scope' => CouponScope::Product]),
                'category' => Coupon::factory()->create(['scope' => CouponScope::Category]),
                'brand' => Coupon::factory()->create(['scope' => CouponScope::Brand]),
                default => Coupon::factory()->create(['value' => 10]),
            };
            match ($case) {
                'product' => $coupon->products()->attach($eligible),
                'category' => $coupon->categories()->attach($eligible->category_id),
                'brand' => $coupon->brands()->attach($eligible->brand_id),
                default => null,
            };

            $requestKey = $this->quote($customer, ['coupon_code' => $coupon->code])->viewData('requestKey');
            $this->post(route('checkout.cod.store'), $this->payload([
                'request_key' => $requestKey, 'coupon_code' => $coupon->code,
            ]))->assertRedirect();

            $order = Order::query()->where('user_id', $customer->id)->sole();
            $this->assertSame($coupon->id, $order->coupon_id);
            $this->assertSame($coupon->code, $order->coupon_snapshot_json['code']);
            $this->assertSame($order->item_discount_vnd, $order->items->sum('discount_vnd'));
            $this->assertSame(
                $order->items_subtotal_vnd - $order->item_discount_vnd
                    + $order->shipping_fee_vnd - $order->shipping_discount_vnd,
                $order->total_vnd,
            );
        }
    }

    public function test_quote_is_revalidated_and_any_change_rolls_back_order_inventory_and_cart(): void
    {
        $customer = User::factory()->create();
        $product = $this->addLine($customer, ['price_vnd' => 100_000], 2);
        $requestKey = $this->quote($customer)->viewData('requestKey');
        $product->update(['price_vnd' => 120_000]);

        $this->post(route('checkout.cod.store'), $this->payload(['request_key' => $requestKey]))
            ->assertRedirect(route('checkout.show'))
            ->assertSessionHasErrors('request_key');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertDatabaseHas('cart_items', ['user_id' => $customer->id, 'product_id' => $product->id, 'quantity' => 2]);
        $this->assertSame(10, $product->refresh()->sellable_quantity);
    }

    public function test_active_vnpay_reservation_reduces_cod_availability(): void
    {
        $customer = User::factory()->create();
        $product = $this->addLine($customer, ['price_vnd' => 100_000, 'sellable_quantity' => 2], 2);
        $requestKey = $this->quote($customer)->viewData('requestKey');
        StockReservation::factory()->for($product)->create(['quantity' => 1, 'expires_at' => now()->addMinutes(10)]);

        $this->post(route('checkout.cod.store'), $this->payload(['request_key' => $requestKey]))
            ->assertSessionHasErrors('cart');
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(2, $product->refresh()->sellable_quantity);
        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_other_province_uses_the_current_other_region_shipping_rate(): void
    {
        $customer = User::factory()->create();
        $this->addLine($customer, ['price_vnd' => 100_000]);
        $input = ['province' => 'Da Nang'];
        $requestKey = $this->quote($customer, $input)->viewData('requestKey');

        $this->post(route('checkout.cod.store'), $this->payload(array_merge($input, [
            'request_key' => $requestKey,
        ])))->assertRedirect();

        $order = Order::query()->sole();
        $this->assertSame(45_000, $order->shipping_fee_vnd);
        $this->assertSame(145_000, $order->total_vnd);
    }

    public function test_replay_is_exactly_once_and_does_not_delete_a_new_cart_line(): void
    {
        $customer = User::factory()->create();
        $product = $this->addLine($customer, ['price_vnd' => 100_000], 1);
        $requestKey = $this->quote($customer)->viewData('requestKey');
        $payload = $this->payload(['request_key' => $requestKey]);

        $first = $this->post(route('checkout.cod.store'), $payload);
        $order = Order::query()->sole();
        $newProduct = $this->addLine($customer, ['price_vnd' => 50_000], 1);
        session()->forget('checkout.cod_confirmation');
        $this->post(route('checkout.cod.store'), $payload)
            ->assertRedirect(route('orders.show', $order->order_code));

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseCount('order_status_histories', 1);
        $this->assertDatabaseCount('inventory_transactions', 1);
        $this->assertSame(9, $product->refresh()->sellable_quantity);
        $this->assertDatabaseHas('cart_items', ['user_id' => $customer->id, 'product_id' => $newProduct->id]);
        $first->assertRedirect(route('orders.show', $order->order_code));
    }

    public function test_same_request_key_with_changed_recipient_conflicts_without_side_effects(): void
    {
        $customer = User::factory()->create();
        $this->addLine($customer);
        $requestKey = $this->quote($customer)->viewData('requestKey');
        $payload = $this->payload(['request_key' => $requestKey]);
        $this->post(route('checkout.cod.store'), $payload)->assertRedirect();

        $this->post(route('checkout.cod.store'), array_merge($payload, ['recipient_name' => 'Người nhận khác']))
            ->assertSessionHasErrors('request_key');
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('inventory_transactions', 1);
    }

    public function test_same_key_with_a_new_cart_fingerprint_conflicts_and_preserves_that_cart(): void
    {
        $customer = User::factory()->create();
        $this->addLine($customer);
        $requestKey = $this->quote($customer)->viewData('requestKey');
        $this->post(route('checkout.cod.store'), $this->payload(['request_key' => $requestKey]))->assertRedirect();

        $newProduct = $this->addLine($customer, ['price_vnd' => 150_000]);
        $recipient = new CheckoutRecipient('Nguyen Minh Anh', 'receiver@example.test', '0912345678', 'Ha Noi', 'Cau Giay', 'Dich Vong', '12 Tran Thai Tong');
        $quote = app(BuildCheckoutQuote::class)->handle($customer, $recipient);
        $changedFingerprint = app(BuildCodOrderFingerprint::class)->handle($customer, $requestKey, $quote, null)['fingerprint'];

        try {
            app(CreateCodOrder::class)->handle($customer, $recipient, $requestKey, null, $changedFingerprint);
            $this->fail('A changed canonical cart payload reused an existing request key.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('request_key', $exception->errors());
        }

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('cart_items', ['user_id' => $customer->id, 'product_id' => $newProduct->id]);
    }

    public function test_access_request_whitelist_post_only_and_receipt_ownership_are_enforced(): void
    {
        $customer = User::factory()->create();
        $other = User::factory()->create();
        $employee = User::factory()->employee()->create();
        $admin = User::factory()->admin()->create();
        $snapshotProduct = $this->addLine($customer, ['name' => '<script>alert(1)</script>', 'price_vnd' => 125_000]);

        $this->post(route('checkout.cod.store'), [])->assertRedirect(route('login'));
        $this->actingAs($employee)->post(route('checkout.cod.store'), $this->payload())->assertForbidden();
        $this->actingAs($admin)->post(route('checkout.cod.store'), $this->payload())->assertForbidden();
        $this->actingAs($customer)->get('/checkout/cod')->assertStatus(405);

        $requestKey = $this->quote($customer)->viewData('requestKey');
        $this->post(route('checkout.cod.store'), $this->payload([
            'request_key' => $requestKey,
            'customer_id' => $other->id,
            'payment_status' => 'da_thanh_toan',
            'total_vnd' => 1,
            'role' => 'admin',
        ]))->assertRedirect();
        $order = Order::query()->sole();
        $this->assertSame($customer->id, $order->user_id);
        $this->assertSame(PaymentStatus::Unpaid, $order->payment_status);
        $this->assertNotSame(1, $order->total_vnd);

        $snapshotProduct->update(['name' => 'Current catalog name', 'price_vnd' => 999_000]);
        auth()->logout();
        $this->get(route('orders.show', $order->order_code))->assertRedirect(route('login'));
        $this->actingAs($other)->get(route('orders.show', $order->order_code))->assertNotFound();
        $this->actingAs($employee)->get(route('orders.show', $order->order_code))->assertForbidden();
        $this->actingAs($customer)->get(route('orders.show', $order->order_code))
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertSee('125.000', false)
            ->assertDontSee('Current catalog name')
            ->assertDontSee('999.000', false)
            ->assertDontSee('VNPay')
            ->assertDontSee('Hoàn tiền');
    }

    public function test_cod_validation_is_vietnamese_and_never_flashes_unknown_fields(): void
    {
        $customer = User::factory()->create();
        $this->addLine($customer);
        $requestKey = $this->quote($customer)->viewData('requestKey');

        $this->post(route('checkout.cod.store'), $this->payload([
            'request_key' => $requestKey,
            'recipient_name' => ['invalid'],
            'password' => 'must-not-be-flashed',
            'customer_id' => 999,
        ]))
            ->assertSessionHasErrors(['recipient_name' => 'Tên người nhận phải là chuỗi ký tự.'])
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.customer_id');
    }

    public function test_locked_and_inactive_customers_cannot_create_cod_orders(): void
    {
        foreach ([User::factory()->locked()->create(), User::factory()->inactive()->create()] as $customer) {
            $this->addLine($customer);
            $this->actingAs($customer)->post(route('checkout.cod.store'), $this->payload())
                ->assertRedirect(route('login'));
        }
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_database_rejects_an_invalid_cod_fingerprint(): void
    {
        $order = Order::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('orders')->where('id', $order->id)->update(['idempotency_fingerprint' => null]);
    }

    public function test_shipping_fee_change_and_coupon_capacity_each_rollback_everything(): void
    {
        $customer = User::factory()->create();
        $product = $this->addLine($customer);
        $requestKey = $this->quote($customer)->viewData('requestKey');
        ShippingRate::query()->where('region_key', 'ha_noi')->update(['fee_vnd' => 45_000]);
        $this->post(route('checkout.cod.store'), $this->payload(['request_key' => $requestKey]))
            ->assertSessionHasErrors('request_key');
        $this->assertSame(0, Order::query()->where('user_id', $customer->id)->count());

        $coupon = Coupon::factory()->create(['max_uses' => 1]);
        CouponUsage::factory()->consumed()->create(['coupon_id' => $coupon->id]);
        $requestKey = $this->quote($customer, ['coupon_code' => $coupon->code])->viewData('requestKey');
        $this->post(route('checkout.cod.store'), $this->payload([
            'request_key' => $requestKey, 'coupon_code' => $coupon->code,
        ]))->assertSessionHasErrors('coupon_code');
        $this->assertSame(0, Order::query()->where('user_id', $customer->id)->count());
        $this->assertSame(10, $product->refresh()->sellable_quantity);
        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_expired_at_now_reservation_does_not_reduce_cod_availability(): void
    {
        $at = now()->startOfSecond();
        $this->travelTo($at);
        $customer = User::factory()->create();
        $product = $this->addLine($customer, ['sellable_quantity' => 1], 1);
        StockReservation::factory()->for($product)->create([
            'quantity' => 1,
            'expires_at' => $at,
        ]);

        $requestKey = $this->quote($customer)->viewData('requestKey');
        $this->post(route('checkout.cod.store'), $this->payload(['request_key' => $requestKey]))
            ->assertRedirect();

        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(0, $product->refresh()->sellable_quantity);
    }

    public function test_history_ledger_coupon_usage_and_cart_cleanup_failures_rollback_the_entire_order(): void
    {
        $cases = [
            'history' => ['table' => 'order_status_histories', 'event' => 'INSERT', 'coupon' => false],
            'ledger' => ['table' => 'inventory_transactions', 'event' => 'INSERT', 'coupon' => false],
            'coupon_usage' => ['table' => 'coupon_usages', 'event' => 'INSERT', 'coupon' => true],
            'cart_cleanup' => ['table' => 'cart_items', 'event' => 'DELETE', 'coupon' => false],
        ];

        foreach ($cases as $name => $case) {
            $customer = User::factory()->create();
            $product = $this->addLine($customer, ['sellable_quantity' => 10], 1);
            $coupon = $case['coupon'] ? Coupon::factory()->create() : null;
            $requestKey = $this->quote($customer, ['coupon_code' => $coupon?->code])->viewData('requestKey');
            $trigger = 'cod_rollback_'.$name;
            DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$case['event']} ON {$case['table']}
                BEGIN SELECT RAISE(ABORT, 'forced COD rollback'); END");

            try {
                $this->post(route('checkout.cod.store'), $this->payload([
                    'request_key' => $requestKey,
                    'coupon_code' => $coupon?->code,
                ]))->assertServerError();
            } finally {
                DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
            }

            $this->assertSame(0, Order::query()->where('user_id', $customer->id)->count());
            $this->assertDatabaseMissing('inventory_transactions', ['product_id' => $product->id]);
            $this->assertDatabaseMissing('coupon_usages', ['customer_id' => $customer->id]);
            $this->assertDatabaseHas('cart_items', ['user_id' => $customer->id, 'product_id' => $product->id]);
            $this->assertSame(10, $product->refresh()->sellable_quantity);
        }
    }

    public function test_last_product_becoming_unavailable_rolls_back_without_touching_earlier_products(): void
    {
        $customer = User::factory()->create();
        $first = $this->addLine($customer, ['sellable_quantity' => 2], 1);
        $last = $this->addLine($customer, ['sellable_quantity' => 2], 2);
        $requestKey = $this->quote($customer)->viewData('requestKey');
        StockReservation::factory()->for($last)->create(['quantity' => 1, 'expires_at' => now()->addMinute()]);

        $this->post(route('checkout.cod.store'), $this->payload(['request_key' => $requestKey]))
            ->assertSessionHasErrors('cart');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertDatabaseCount('cart_items', 2);
        $this->assertSame(2, $first->refresh()->sellable_quantity);
        $this->assertSame(2, $last->refresh()->sellable_quantity);
    }

    public function test_cod_read_query_count_does_not_grow_with_cart_line_count(): void
    {
        $selectCounts = [];

        foreach ([1, 5] as $lineCount) {
            $customer = User::factory()->create();
            for ($index = 0; $index < $lineCount; $index++) {
                $this->addLine($customer, ['sku' => "QUERY-{$lineCount}-{$index}"]);
            }
            $requestKey = $this->quote($customer)->viewData('requestKey');
            $fingerprint = session('checkout.cod_confirmation.fingerprint');
            $recipient = new CheckoutRecipient(
                'Nguyen Minh Anh', 'receiver@example.test', '0912345678',
                'Ha Noi', 'Cau Giay', 'Dich Vong', '12 Tran Thai Tong',
            );

            DB::flushQueryLog();
            DB::enableQueryLog();
            app(CreateCodOrder::class)->handle($customer, $recipient, $requestKey, null, $fingerprint);
            $selectCounts[] = collect(DB::getQueryLog())
                ->filter(fn (array $query): bool => str_starts_with(strtolower(ltrim($query['query'])), 'select'))
                ->count();
            DB::disableQueryLog();
        }

        $this->assertSame($selectCounts[0], $selectCounts[1]);
    }

    private function quote(User $customer, array $overrides = [])
    {
        return $this->actingAs($customer)
            ->post(route('checkout.quote'), $this->payload($overrides))
            ->assertOk();
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'recipient_name' => 'Nguyen Minh Anh',
            'recipient_email' => 'receiver@example.test',
            'recipient_phone' => '0912345678',
            'province' => 'Ha Noi',
            'district' => 'Cau Giay',
            'ward' => 'Dich Vong',
            'address_line' => '12 Tran Thai Tong',
            'coupon_code' => '',
        ], $overrides);
    }

    private function addLine(User $customer, array $attributes = [], int $quantity = 1): Product
    {
        $product = Product::factory()->inStock(max(10, $quantity))->create($attributes);
        CartItem::factory()->for($customer)->for($product)->create(['quantity' => $quantity]);

        return $product;
    }
}
