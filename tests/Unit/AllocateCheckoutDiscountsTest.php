<?php

namespace Tests\Unit;

use App\Actions\AllocateCheckoutDiscounts;
use App\Enums\CouponScope;
use App\Enums\CouponType;
use App\Models\Coupon;
use App\Models\Product;
use App\ValueObjects\CheckoutCouponSnapshot;
use App\ValueObjects\CheckoutQuote;
use App\ValueObjects\CheckoutQuoteLine;
use App\ValueObjects\CheckoutRecipient;
use App\ValueObjects\CheckoutShippingSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;
use TypeError;

class AllocateCheckoutDiscountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_discount_and_free_shipping_allocate_zero_to_every_line(): void
    {
        $lines = $this->lines([[2, 100], [1, 200]]);
        $none = $this->quote($lines, 0, null);
        $this->assertSame([1 => 0, 2 => 0], app(AllocateCheckoutDiscounts::class)->handle($none, null));

        $coupon = Coupon::factory()->freeShipping()->create();
        $free = $this->quote($lines, 0, $coupon, 300, 30);
        $this->assertSame([1 => 0, 2 => 0], app(AllocateCheckoutDiscounts::class)->handle($free, $coupon));
    }

    public function test_one_line_and_fixed_discount_cap_are_exact(): void
    {
        $coupon = Coupon::factory()->fixed(100)->create();
        $quote = $this->quote($this->lines([[7, 100]]), 100, $coupon, 100);

        $this->assertSame([7 => 100], app(AllocateCheckoutDiscounts::class)->handle($quote, $coupon));
    }

    public function test_even_split_largest_remainder_and_product_id_tie_break_are_deterministic(): void
    {
        $coupon = Coupon::factory()->fixed(2)->create();
        $allocator = app(AllocateCheckoutDiscounts::class);
        $ordered = $this->lines([[3, 1], [1, 1], [2, 1]]);
        $reversed = array_reverse($ordered);

        $first = $allocator->handle($this->quote($ordered, 2, $coupon, 3), $coupon);
        $second = $allocator->handle($this->quote($reversed, 2, $coupon, 3), $coupon);

        $this->assertSame([1 => 1, 2 => 1, 3 => 0], $first);
        $this->assertSame($first, $second);
        $this->assertSame(2, array_sum($first));
    }

    public function test_scope_filters_only_eligible_product_category_and_brand_lines(): void
    {
        $products = [Product::factory()->create(), Product::factory()->create()];
        $lines = [
            new CheckoutQuoteLine($products[0]->id, $products[0]->category_id, $products[0]->brand_id, 'A', 'A', 1, 100, 100),
            new CheckoutQuoteLine($products[1]->id, $products[1]->category_id, $products[1]->brand_id, 'B', 'B', 1, 200, 200),
        ];
        $cases = [
            [CouponScope::Product, 'coupon_products', 'product_id', $products[0]->id],
            [CouponScope::Category, 'coupon_categories', 'category_id', $products[0]->category_id],
            [CouponScope::Brand, 'coupon_brands', 'brand_id', $products[0]->brand_id],
        ];

        foreach ($cases as [$scope, $table, $column, $target]) {
            $coupon = Coupon::factory()->fixed(10)->create(['scope' => $scope]);
            DB::table($table)->insert(['coupon_id' => $coupon->id, $column => $target]);
            $allocation = app(AllocateCheckoutDiscounts::class)->handle(
                $this->quote($lines, 10, $coupon, 100),
                $coupon,
            );
            $this->assertSame(10, $allocation[$products[0]->id]);
            $this->assertSame(0, $allocation[$products[1]->id]);
        }
    }

    public function test_cart_scope_percent_total_uses_integer_math_and_reconciles_exactly(): void
    {
        $coupon = Coupon::factory()->create(['type' => CouponType::Percent, 'value' => 10]);
        $quote = $this->quote($this->lines([[2, 101], [1, 202]]), 30, $coupon, 303);
        $allocation = app(AllocateCheckoutDiscounts::class)->handle($quote, $coupon);

        $this->assertSame([1 => 20, 2 => 10], $allocation);
        $this->assertSame(30, array_sum($allocation));
        foreach ($allocation as $value) {
            $this->assertIsInt($value);
        }
    }

    public function test_zero_eligible_subtotal_invalid_scope_overflow_and_wrong_input_fail_closed(): void
    {
        $coupon = Coupon::factory()->fixed(1)->create(['scope' => CouponScope::Product]);
        $product = Product::factory()->create();
        $quote = $this->quote([
            new CheckoutQuoteLine($product->id, $product->category_id, $product->brand_id, 'A', 'A', 1, 1, 1),
        ], 1, $coupon, 1);
        $this->expectException(ValidationException::class);
        app(AllocateCheckoutDiscounts::class)->handle($quote, $coupon);
    }

    public function test_quote_rejects_overflow_and_allocator_has_strict_input_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->quote($this->lines([[1, PHP_INT_MAX], [2, PHP_INT_MAX]]), 0, null);
    }

    public function test_allocator_rejects_wrong_input_type(): void
    {
        $this->expectException(TypeError::class);
        app(AllocateCheckoutDiscounts::class)->handle('invalid', null);
    }

    /** @param list<array{int,int}> $definitions */
    private function lines(array $definitions): array
    {
        return array_map(fn (array $line): CheckoutQuoteLine => new CheckoutQuoteLine(
            $line[0], 1, 1, 'SKU-'.$line[0], 'Product '.$line[0], 1, $line[1], $line[1],
        ), $definitions);
    }

    private function quote(array $lines, int $discount, ?Coupon $coupon, ?int $eligible = null, int $shippingDiscount = 0): CheckoutQuote
    {
        $subtotal = 0;
        foreach ($lines as $line) {
            if ($line->lineSubtotalVnd > PHP_INT_MAX - $subtotal) {
                throw new InvalidArgumentException('test subtotal overflow');
            }
            $subtotal += $line->lineSubtotalVnd;
        }
        $shippingFee = 30;
        $couponSnapshot = $coupon === null ? null : new CheckoutCouponSnapshot(
            $coupon->id, $coupon->code, $coupon->type->value, $coupon->scope->value,
            $coupon->value, $eligible ?? $subtotal,
        );

        return new CheckoutQuote(
            new CheckoutRecipient('A', 'a@example.test', '0912345678', 'Ha Noi', 'D', 'W', 'Address'),
            new CheckoutShippingSnapshot(1, 'ha_noi', 'Hà Nội', $shippingFee),
            $lines, $couponSnapshot, $subtotal, $discount, $shippingFee, $shippingDiscount,
            $shippingFee - $shippingDiscount, $discount + $shippingDiscount,
            $subtotal - $discount + $shippingFee - $shippingDiscount,
            CarbonImmutable::now('UTC'),
        );
    }
}
