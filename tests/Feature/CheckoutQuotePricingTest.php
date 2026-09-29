<?php

namespace Tests\Feature;

use App\Actions\BuildCheckoutQuote;
use App\Enums\CouponScope;
use App\Enums\MembershipLevel;
use App\Models\Brand;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\User;
use App\ValueObjects\CheckoutRecipient;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CheckoutQuotePricingTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_quote_reprices_every_line_and_calculates_integer_totals_on_the_server(): void
    {
        $customer = User::factory()->create();
        $regular = $this->addLine($customer, ['price_vnd' => 125_000, 'sale_price_vnd' => null], 2);
        $sale = $this->addLine($customer, ['price_vnd' => 200_000, 'sale_price_vnd' => 150_000], 3);
        $sale->update(['sale_price_vnd' => 140_000]);

        $quote = $this->actingAs($customer)->post(route('checkout.quote'), $this->payload([
            'cart_subtotal_vnd' => 1,
            'shipping_fee_vnd' => 1,
            'grand_total_vnd' => 1,
        ]))->assertOk()->viewData('quote');

        $this->assertSame(670_000, $quote->cartSubtotalVnd);
        $this->assertSame(30_000, $quote->shippingFeeVnd);
        $this->assertSame(700_000, $quote->grandTotalVnd);
        $this->assertSame(0, $quote->totalDiscountVnd);
        $this->assertNull($quote->coupon);
        $this->assertSame([$regular->id, $sale->id], array_column($quote->lines, 'productId'));
        $this->assertSame([250_000, 420_000], array_column($quote->lines, 'lineSubtotalVnd'));
    }

    public function test_quote_rejects_any_hidden_or_unavailable_cart_line_as_one_consistent_quote(): void
    {
        foreach ([
            fn () => Product::factory()->hidden()->inStock(10)->create(),
            fn () => Product::factory()->for(Category::factory()->hidden(), 'category')->inStock(10)->create(),
            fn () => Product::factory()->for(Brand::factory()->hidden(), 'brand')->inStock(10)->create(),
            fn () => Product::factory()->inStock(1)->create(),
        ] as $index => $makeProduct) {
            $customer = User::factory()->create();
            $product = $makeProduct();
            CartItem::factory()->for($customer)->for($product)->create(['quantity' => $index === 3 ? 2 : 1]);

            $this->actingAs($customer)->post(route('checkout.quote'), $this->payload())
                ->assertSessionHasErrors('cart');
        }
    }

    public function test_quote_rejects_line_overflow_without_mutating_cart_or_inventory(): void
    {
        $customer = User::factory()->create();
        $largePrice = intdiv(PHP_INT_MAX, 2) + 1;
        $product = Product::factory()->inStock(2)->create([
            'price_vnd' => $largePrice,
            'sale_price_vnd' => null,
            'damaged_quantity' => 3,
            'sold_quantity' => 4,
        ]);
        $item = CartItem::factory()->for($customer)->for($product)->create(['quantity' => 2]);
        $beforeProduct = $product->only(['sellable_quantity', 'damaged_quantity', 'sold_quantity']);

        $this->actingAs($customer)->post(route('checkout.quote'), $this->payload())
            ->assertSessionHasErrors('cart');

        $this->assertSame(2, $item->fresh()->quantity);
        $this->assertSame($beforeProduct, $product->fresh()->only(array_keys($beforeProduct)));
        $this->assertSame(0, InventoryTransaction::query()->count());
        $this->assertFalse(Schema::hasTable('orders'));
        $this->assertDatabaseCount('stock_reservations', 0);
    }

    public function test_quote_rejects_cart_and_grand_total_overflow(): void
    {
        $customer = User::factory()->create();
        $largePrice = intdiv(PHP_INT_MAX, 2) + 1;
        $this->addLine($customer, ['price_vnd' => $largePrice, 'sale_price_vnd' => null]);
        $this->addLine($customer, ['price_vnd' => $largePrice, 'sale_price_vnd' => null]);

        $this->actingAs($customer)->post(route('checkout.quote'), $this->payload())
            ->assertRedirect(route('checkout.show'))
            ->assertSessionHasErrors('cart');

        CartItem::query()->delete();
        $this->addLine($customer, ['price_vnd' => 1, 'sale_price_vnd' => null]);
        DB::table('shipping_rates')->where('region_key', 'ha_noi')->update(['fee_vnd' => PHP_INT_MAX]);

        $this->post(route('checkout.quote'), $this->payload())
            ->assertRedirect(route('checkout.show'))
            ->assertSessionHasErrors('cart');
    }

    public function test_cart_coupon_types_apply_with_caps_and_never_claim_usage(): void
    {
        $customer = User::factory()->create();
        $this->addLine($customer, ['price_vnd' => 200_000, 'sale_price_vnd' => null]);

        $percent = Coupon::factory()->create(['code' => 'PERCENT10', 'value' => 10]);
        $fixed = Coupon::factory()->fixed(500_000)->create(['code' => 'FIXED500']);
        $freeShipping = Coupon::factory()->freeShipping()->create(['code' => 'FREESHIP']);

        $percentQuote = $this->actingAs($customer)->post(route('checkout.quote'), $this->payload([
            'coupon_code' => ' percent10 ',
            'coupon_id' => $fixed->id,
            'product_discount_vnd' => 199_999,
            'shipping_discount_vnd' => 30_000,
        ]))->assertOk()->viewData('quote');
        $this->assertSame(20_000, $percentQuote->productDiscountVnd);
        $this->assertSame(210_000, $percentQuote->grandTotalVnd);
        $this->assertSame($percent->id, $percentQuote->coupon->couponId);

        $fixedQuote = $this->post(route('checkout.quote'), $this->payload([
            'coupon_code' => $fixed->code,
        ]))->assertOk()->viewData('quote');
        $this->assertSame(200_000, $fixedQuote->productDiscountVnd);
        $this->assertSame(30_000, $fixedQuote->grandTotalVnd);

        $shippingQuote = $this->post(route('checkout.quote'), $this->payload([
            'coupon_code' => $freeShipping->code,
        ]))->assertOk()->viewData('quote');
        $this->assertSame(0, $shippingQuote->productDiscountVnd);
        $this->assertSame(30_000, $shippingQuote->shippingDiscountVnd);
        $this->assertSame(0, $shippingQuote->shippingFeeAfterDiscountVnd);
        $this->assertSame(200_000, $shippingQuote->grandTotalVnd);
        $this->assertFalse(Schema::hasTable('coupon_usages'));
    }

    public function test_coupon_definition_is_reloaded_for_each_new_quote_request(): void
    {
        $customer = User::factory()->create();
        $this->addLine($customer, ['price_vnd' => 100_000, 'sale_price_vnd' => null]);
        $coupon = Coupon::factory()->create(['code' => 'REFRESH', 'value' => 10]);

        $first = $this->actingAs($customer)->post(route('checkout.quote'), $this->payload([
            'coupon_code' => $coupon->code,
        ]))->assertOk()->viewData('quote');
        $this->assertSame(10_000, $first->productDiscountVnd);

        $coupon->update(['value' => 25]);

        $second = $this->post(route('checkout.quote'), $this->payload([
            'coupon_code' => $coupon->code,
        ]))->assertOk()->viewData('quote');
        $this->assertSame(25_000, $second->productDiscountVnd);
        $this->assertSame(105_000, $second->grandTotalVnd);
        $this->assertSame(10_000, $first->productDiscountVnd);
    }

    public function test_product_category_and_brand_scopes_only_discount_matching_lines(): void
    {
        foreach ([CouponScope::Product, CouponScope::Category, CouponScope::Brand] as $scope) {
            $customer = User::factory()->create();
            $target = $this->addLine($customer, ['price_vnd' => 100_000, 'sale_price_vnd' => null]);
            $this->addLine($customer, ['price_vnd' => 400_000, 'sale_price_vnd' => null]);
            $coupon = Coupon::factory()->create([
                'code' => 'SCOPE'.strtoupper($scope->value),
                'scope' => $scope,
                'value' => 10,
            ]);
            [$table, $column, $targetId] = match ($scope) {
                CouponScope::Product => ['coupon_products', 'product_id', $target->id],
                CouponScope::Category => ['coupon_categories', 'category_id', $target->category_id],
                CouponScope::Brand => ['coupon_brands', 'brand_id', $target->brand_id],
                default => throw new \LogicException,
            };
            DB::table($table)->insert(['coupon_id' => $coupon->id, $column => $targetId]);

            $quote = $this->actingAs($customer)->post(route('checkout.quote'), $this->payload([
                'coupon_code' => $coupon->code,
            ]))->assertOk()->viewData('quote');

            $this->assertSame(100_000, $quote->coupon->eligibleSubtotalVnd);
            $this->assertSame(10_000, $quote->productDiscountVnd);
            $this->assertSame(520_000, $quote->grandTotalVnd);
        }
    }

    public function test_coupon_definition_failures_return_field_errors_and_do_not_change_the_cart(): void
    {
        $customer = User::factory()->create(['current_tier' => MembershipLevel::Bac]);
        $product = $this->addLine($customer, ['price_vnd' => 100_000, 'sale_price_vnd' => null]);
        $item = $customer->cartItems()->firstOrFail();
        $cases = [
            Coupon::factory()->inactive()->create(['code' => 'INACTIVE']),
            Coupon::factory()->create(['code' => 'FUTURE', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]),
            Coupon::factory()->create(['code' => 'EXPIRED', 'starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]),
            Coupon::factory()->create(['code' => 'TIER', 'required_tier' => MembershipLevel::Vang]),
            Coupon::factory()->create(['code' => 'MINIMUM', 'min_subtotal_vnd' => 100_001]),
        ];

        foreach ($cases as $coupon) {
            $this->actingAs($customer)->post(route('checkout.quote'), $this->payload([
                'coupon_code' => $coupon->code,
            ]))->assertSessionHasErrors('coupon_code');
        }
        $this->post(route('checkout.quote'), $this->payload(['coupon_code' => 'MISSING']))
            ->assertSessionHasErrors('coupon_code');

        $this->assertSame(1, $item->fresh()->quantity);
        $this->assertSame(100_000, $product->fresh()->price_vnd);
    }

    public function test_quote_query_count_is_bounded_and_action_rejects_non_customer_direct_calls(): void
    {
        $customer = User::factory()->create();
        foreach (Product::factory()->count(10)->inStock(10)->create() as $product) {
            CartItem::factory()->for($customer)->for($product)->create(['quantity' => 1]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAs($customer)->post(route('checkout.quote'), $this->payload())->assertOk();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(12, $queryCount);
        $this->assertCount(10, $response->viewData('quote')->lines);

        $employee = User::factory()->employee()->create();
        $recipient = new CheckoutRecipient('A', 'a@example.test', '0912345678', 'Ha Noi', 'A', 'B', 'C');
        $this->expectException(AuthorizationException::class);
        app(BuildCheckoutQuote::class)->handle($employee, $recipient);
    }

    public function test_scoped_coupon_target_lookup_is_bounded_for_many_cart_lines(): void
    {
        $customer = User::factory()->create();
        $products = Product::factory()->count(10)->inStock(10)->create();
        foreach ($products as $product) {
            CartItem::factory()->for($customer)->for($product)->create(['quantity' => 1]);
        }
        $coupon = Coupon::factory()->create([
            'code' => 'BOUNDED',
            'scope' => CouponScope::Product,
        ]);
        DB::table('coupon_products')->insert([
            'coupon_id' => $coupon->id,
            'product_id' => $products->first()->id,
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($customer)->post(route('checkout.quote'), $this->payload([
            'coupon_code' => $coupon->code,
        ]))->assertOk();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(14, $queryCount);
    }
}
