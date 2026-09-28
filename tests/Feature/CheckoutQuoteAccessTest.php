<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Product;
use App\Models\ShippingRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CheckoutQuoteAccessTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'recipient_name' => 'Nguyễn Minh Anh',
            'recipient_email' => 'receiver@example.test',
            'recipient_phone' => '0912 345 678',
            'province' => 'Hà Nội',
            'district' => 'Cầu Giấy',
            'ward' => 'Dịch Vọng',
            'address_line' => 'Số 12 đường Trần Thái Tông',
            'coupon_code' => '',
        ], $overrides);
    }

    private function customerWithCart(array $product = []): array
    {
        $customer = User::factory()->create();
        $itemProduct = Product::factory()->inStock(10)->create($product);
        CartItem::factory()->for($customer)->for($itemProduct)->create(['quantity' => 2]);

        return [$customer, $itemProduct];
    }

    public function test_routes_are_customer_only_and_use_expected_methods(): void
    {
        $this->get(route('checkout.show'))->assertRedirect(route('login'));
        $this->post(route('checkout.quote'), $this->payload())->assertRedirect(route('login'));

        foreach ([User::factory()->employee()->create(), User::factory()->admin()->create()] as $user) {
            $this->actingAs($user)->get(route('checkout.show'))->assertForbidden();
            $this->post(route('checkout.quote'), $this->payload())->assertForbidden();
        }

        [$customer] = $this->customerWithCart();
        $this->actingAs($customer)->get(route('checkout.show'))->assertOk();
        $this->get(route('checkout.quote'))->assertStatus(405);

        $routes = collect(app('router')->getRoutes()->getRoutes());
        $showRoute = $routes->firstWhere('action.as', 'checkout.show');
        $quoteRoute = $routes->firstWhere('action.as', 'checkout.quote');
        $this->assertSame(['GET', 'HEAD'], $showRoute->methods());
        $this->assertSame(['POST'], $quoteRoute->methods());
        $this->assertSame('checkout', $showRoute->uri());
        $this->assertSame('checkout/quote', $quoteRoute->uri());
        foreach (['web', 'auth', 'active', 'role:customer'] as $middleware) {
            $this->assertContains($middleware, $showRoute->gatherMiddleware());
            $this->assertContains($middleware, $quoteRoute->gatherMiddleware());
        }
    }

    public function test_locked_and_inactive_sessions_are_revoked(): void
    {
        foreach ([
            User::factory()->locked()->create(),
            User::factory()->inactive()->create(),
        ] as $customer) {
            $this->actingAs($customer)->get(route('checkout.show'))->assertRedirect(route('login'));
            $this->assertGuest();
        }
    }

    public function test_invalid_stored_role_and_status_fail_safely(): void
    {
        $invalidRole = User::factory()->create();
        DB::statement('PRAGMA ignore_check_constraints = ON');
        try {
            DB::table('users')->where('id', $invalidRole->id)->update(['role' => 'unknown']);
        } finally {
            DB::statement('PRAGMA ignore_check_constraints = OFF');
        }
        $this->actingAs($invalidRole)->get(route('checkout.show'))->assertForbidden();

        $invalidStatus = User::factory()->create();
        DB::statement('PRAGMA ignore_check_constraints = ON');
        try {
            DB::table('users')->where('id', $invalidStatus->id)->update(['status' => 'unknown']);
        } finally {
            DB::statement('PRAGMA ignore_check_constraints = OFF');
        }
        $this->actingAs($invalidStatus)->get(route('checkout.show'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_empty_cart_cannot_open_or_create_quote(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer)->get(route('checkout.show'))
            ->assertRedirect(route('cart.index'))
            ->assertSessionHasErrors('cart');
        $this->get(route('cart.index'))->assertOk()
            ->assertSee('role="alert"', false);
        $this->post(route('checkout.quote'), $this->payload())
            ->assertSessionHasErrors('cart');
    }

    public function test_recipient_fields_are_required_and_invalid_phone_is_rejected_with_old_input(): void
    {
        [$customer] = $this->customerWithCart();

        $this->actingAs($customer)
            ->from(route('checkout.show'))
            ->post(route('checkout.quote'), [
                'recipient_name' => '   ',
                'recipient_email' => 'invalid',
                'recipient_phone' => '123',
                'province' => ' ',
                'district' => ' ',
                'ward' => ' ',
                'address_line' => ' ',
            ])
            ->assertRedirect(route('checkout.show'))
            ->assertSessionHasErrors([
                'recipient_name',
                'recipient_email',
                'recipient_phone',
                'province',
                'district',
                'ward',
                'address_line',
            ])
            ->assertSessionHasInput('recipient_email', 'invalid');
    }

    public function test_validation_and_business_errors_always_redirect_to_checkout_show(): void
    {
        [$customer] = $this->customerWithCart();

        $this->actingAs($customer)
            ->from(route('checkout.quote'))
            ->post(route('checkout.quote'), $this->payload([
                'recipient_phone' => '123',
                'shipping_fee_vnd' => 1,
                'recipient_snapshot_json' => '{"trusted":false}',
            ]))
            ->assertRedirect(route('checkout.show'))
            ->assertSessionHasErrors('recipient_phone')
            ->assertSessionHas('_old_input', function (array $input): bool {
                return ! array_key_exists('shipping_fee_vnd', $input)
                    && ! array_key_exists('recipient_snapshot_json', $input);
            });

        $this->from(route('checkout.quote'))
            ->post(route('checkout.quote'), $this->payload(['coupon_code' => 'MISSING']))
            ->assertRedirect(route('checkout.show'))
            ->assertSessionHasErrors('coupon_code');
    }

    public function test_phone_rejects_non_scalar_scientific_and_malformed_values(): void
    {
        [$customer] = $this->customerWithCart();

        foreach ([true, ['0912345678'], '9.12345678e8', '09123A5678', '0212345678', '091234567'] as $phone) {
            $this->actingAs($customer)
                ->post(route('checkout.quote'), $this->payload(['recipient_phone' => $phone]))
                ->assertRedirect(route('checkout.show'))
                ->assertSessionHasErrors('recipient_phone');
        }
    }

    public function test_type_and_length_validation_messages_are_vietnamese(): void
    {
        [$customer] = $this->customerWithCart();

        $this->actingAs($customer)
            ->post(route('checkout.quote'), $this->payload([
                'recipient_name' => ['not-a-string'],
                'province' => str_repeat('a', 101),
                'coupon_code' => str_repeat('X', 81),
            ]))
            ->assertSessionHasErrors([
                'recipient_name' => 'Tên người nhận phải là chuỗi ký tự.',
                'province' => 'Tỉnh hoặc thành phố không được vượt quá 100 ký tự.',
                'coupon_code' => 'Mã giảm giá không được vượt quá 80 ký tự.',
            ]);
    }

    public function test_recipient_is_normalized_in_snapshot_and_never_overwrites_user(): void
    {
        [$customer] = $this->customerWithCart();
        $before = $customer->only(['name', 'email', 'phone', 'address']);

        $response = $this->actingAs($customer)->post(route('checkout.quote'), $this->payload([
            'recipient_name' => '  Nguyễn   Văn B  ',
            'recipient_email' => '  RECEIVER@EXAMPLE.TEST ',
            'recipient_phone' => '+84 912 345 678',
            'province' => '  Hà   Nội ',
            'district' => '  Cầu   Giấy ',
            'ward' => ' Dịch  Vọng ',
            'address_line' => ' Số  12   đường A ',
            'user_id' => 999,
            'shipping_fee_vnd' => 1,
            'total_vnd' => 1,
            'recipient_snapshot_json' => '{"admin":true}',
        ]))->assertOk();

        $recipient = $response->viewData('quote')->recipient;
        $this->assertSame('Nguyễn Văn B', $recipient->name);
        $this->assertSame('receiver@example.test', $recipient->email);
        $this->assertSame('0912345678', $recipient->phone);
        $this->assertSame('Hà Nội', $recipient->province);
        $this->assertSame('Số 12 đường A, Dịch Vọng, Cầu Giấy, Hà Nội', $recipient->fullAddress());
        $this->assertSame([
            'recipient_name' => 'Nguyễn Văn B',
            'recipient_email' => 'receiver@example.test',
            'recipient_phone' => '0912345678',
            'recipient_address' => 'Số 12 đường A, Dịch Vọng, Cầu Giấy, Hà Nội',
            'recipient_region' => 'Hà Nội',
        ], $recipient->snapshot());
        $this->assertSame($before, $customer->fresh()->only(['name', 'email', 'phone', 'address']));
    }

    public function test_shipping_region_is_server_mapped_and_client_money_is_ignored(): void
    {
        [$customer] = $this->customerWithCart(['price_vnd' => 100_000, 'sale_price_vnd' => null]);
        ShippingRate::query()->where('region_key', 'ha_noi')->update(['fee_vnd' => 31_000]);
        ShippingRate::query()->where('region_key', 'other')->update(['fee_vnd' => 46_000]);

        $haNoi = $this->actingAs($customer)->post(route('checkout.quote'), $this->payload([
            'province' => 'Ha Noi',
            'shipping_rate_id' => 999,
            'region_key' => 'other',
            'shipping_fee_vnd' => 1,
        ]))->assertOk()->viewData('quote');
        $this->assertSame('ha_noi', $haNoi->shipping->regionKey);
        $this->assertSame(31_000, $haNoi->shippingFeeVnd);

        $other = $this->post(route('checkout.quote'), $this->payload([
            'province' => 'Đà Nẵng',
            'shipping_fee_vnd' => 1,
        ]))->assertOk()->viewData('quote');
        $this->assertSame('other', $other->shipping->regionKey);
        $this->assertSame(46_000, $other->shippingFeeVnd);
    }

    public function test_hanoi_region_recognizes_case_diacritic_and_whitespace_variants_only(): void
    {
        [$customer] = $this->customerWithCart();

        foreach (['Hà Nội', 'Ha Noi', 'HÀ NỘI', '   Hà    Nội   ', 'hA nOi'] as $province) {
            $quote = $this->actingAs($customer)->post(route('checkout.quote'), $this->payload([
                'province' => $province,
            ]))->assertOk()->viewData('quote');

            $this->assertSame('ha_noi', $quote->shipping->regionKey);
        }

        foreach (['Hà Nam', 'Quảng Ninh', 'Thành phố Hồ Chí Minh'] as $province) {
            $quote = $this->post(route('checkout.quote'), $this->payload([
                'province' => $province,
            ]))->assertOk()->viewData('quote');

            $this->assertSame('other', $quote->shipping->regionKey);
        }
    }

    public function test_shipping_snapshot_is_independent_and_missing_rate_fails_safely(): void
    {
        [$customer] = $this->customerWithCart();
        $quote = $this->actingAs($customer)
            ->post(route('checkout.quote'), $this->payload())
            ->assertOk()
            ->viewData('quote');
        $snapshot = $quote->shipping->toArray();

        ShippingRate::query()->where('region_key', 'ha_noi')->update(['fee_vnd' => 99_000]);
        $this->assertSame(30_000, $quote->shippingFeeVnd);
        $this->assertSame(30_000, $snapshot['shipping_fee_vnd']);

        DB::statement('DROP TRIGGER shipping_rates_delete_guard');
        DB::table('shipping_rates')->where('region_key', 'ha_noi')->delete();

        $this->post(route('checkout.quote'), $this->payload())
            ->assertSessionHasErrors('province');
    }

    public function test_checkout_view_is_escaped_and_has_no_fake_order_or_payment_action(): void
    {
        [$customer, $product] = $this->customerWithCart();
        $product->update(['name' => '<script>alert(1)</script>']);

        $this->actingAs($customer)->get(route('checkout.show'))->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('action="'.route('checkout.quote').'"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('Tạo bảng tính tạm thời')
            ->assertDontSee('Đặt hàng')
            ->assertDontSee('Thanh toán');
    }

    public function test_checkout_renders_linked_validation_summary_and_field_errors(): void
    {
        [$customer] = $this->customerWithCart();

        $this->actingAs($customer)
            ->from(route('checkout.show'))
            ->post(route('checkout.quote'), $this->payload([
                'recipient_name' => '',
                'recipient_phone' => '123',
            ]))
            ->assertRedirect(route('checkout.show'));

        $this->get(route('checkout.show'))->assertOk()
            ->assertSee('id="checkout-errors-title"', false)
            ->assertSee('href="#recipient_name"', false)
            ->assertSee('href="#recipient_phone"', false)
            ->assertSee('aria-invalid="true"', false);
    }

    public function test_cart_only_offers_quote_when_every_line_is_valid(): void
    {
        [$customer, $product] = $this->customerWithCart();
        $this->actingAs($customer)->get(route('cart.index'))->assertOk()
            ->assertSee(route('checkout.show'), false);

        DB::table('products')->where('id', $product->id)->update(['sellable_quantity' => 1]);
        $this->get(route('cart.index'))->assertOk()
            ->assertDontSee(route('checkout.show'), false);
    }
}
