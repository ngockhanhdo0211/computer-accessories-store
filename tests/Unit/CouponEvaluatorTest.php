<?php

namespace Tests\Unit;

use App\Actions\EvaluateCoupon;
use App\Enums\CouponScope;
use App\Enums\CouponType;
use App\Enums\MembershipLevel;
use App\Models\Coupon;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class CouponEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    private function line(Product $product, int $price = 100_000, int $quantity = 1): array
    {
        return ['product_id' => $product->id, 'category_id' => $product->category_id, 'brand_id' => $product->brand_id, 'unit_price_vnd' => $price, 'quantity' => $quantity];
    }

    public function test_percent_uses_integer_floor_and_fixed_never_exceeds_eligible_subtotal(): void
    {
        $product = Product::factory()->create();
        $percent = Coupon::factory()->create(['value' => 33]);
        $fixed = Coupon::factory()->fixed(500_000)->create();
        $action = app(EvaluateCoupon::class);

        $this->assertSame(33, $action->handle($percent, [$this->line($product, 101)], 30_000)->itemDiscountVnd);
        $this->assertSame(100_000, $action->handle($fixed, [$this->line($product)], 30_000)->itemDiscountVnd);
    }

    public function test_free_shipping_only_discounts_shipping_and_all_type_scope_pairs_are_supported(): void
    {
        $product = Product::factory()->create();
        $action = app(EvaluateCoupon::class);

        foreach (CouponType::cases() as $type) {
            foreach (CouponScope::cases() as $scope) {
                $value = match ($type) {
                    CouponType::Percent => 10, CouponType::Fixed => 10_000, CouponType::FreeShipping => 0
                };
                $coupon = Coupon::factory()->create(['type' => $type, 'scope' => $scope, 'value' => $value]);
                if ($scope !== CouponScope::Cart) {
                    [$table, $column, $id] = match ($scope) {
                        CouponScope::Product => ['coupon_products', 'product_id', $product->id],
                        CouponScope::Category => ['coupon_categories', 'category_id', $product->category_id],
                        CouponScope::Brand => ['coupon_brands', 'brand_id', $product->brand_id],
                        default => throw new \LogicException,
                    };
                    DB::table($table)->insert(['coupon_id' => $coupon->id, $column => $id]);
                }
                $result = $action->handle($coupon, [$this->line($product)], 45_000);
                $this->assertTrue($result->eligible);
                if ($type === CouponType::FreeShipping) {
                    $this->assertSame(0, $result->itemDiscountVnd);
                    $this->assertSame(45_000, $result->shippingDiscountVnd);
                }
            }
        }
    }

    public function test_scope_only_sums_matching_lines_and_minimum_uses_eligible_subtotal(): void
    {
        $target = Product::factory()->create();
        $other = Product::factory()->create();
        $coupon = Coupon::factory()->create(['scope' => CouponScope::Product, 'min_subtotal_vnd' => 200_000]);
        DB::table('coupon_products')->insert(['coupon_id' => $coupon->id, 'product_id' => $target->id]);

        $result = app(EvaluateCoupon::class)->handle($coupon, [$this->line($target, 150_000), $this->line($other, 500_000)], 30_000);
        $this->assertFalse($result->eligible);
        $this->assertSame('minimum_not_met', $result->reason);
        $this->assertSame(150_000, $result->eligibleSubtotalVnd);
    }

    public function test_active_time_and_tier_rules_fail_with_stable_reasons(): void
    {
        $product = Product::factory()->create();
        $at = CarbonImmutable::parse('2026-06-01 00:00:00', 'UTC');
        $base = ['starts_at' => $at->subDay(), 'ends_at' => $at->addDay()];
        $action = app(EvaluateCoupon::class);

        foreach ([
            ['override' => ['is_active' => false] + $base, 'tier' => MembershipLevel::Dong, 'reason' => 'inactive'],
            ['override' => ['starts_at' => $at->addMinute(), 'ends_at' => $at->addDay()], 'tier' => MembershipLevel::Dong, 'reason' => 'not_started'],
            ['override' => ['starts_at' => $at->subDay(), 'ends_at' => $at->subMinute()], 'tier' => MembershipLevel::Dong, 'reason' => 'expired'],
            ['override' => ['required_tier' => MembershipLevel::Vang] + $base, 'tier' => MembershipLevel::Bac, 'reason' => 'tier_not_eligible'],
        ] as $case) {
            $coupon = Coupon::factory()->create($case['override']);
            $result = $action->handle($coupon, [$this->line($product)], 30_000, $case['tier'], $at);
            $this->assertFalse($result->eligible);
            $this->assertSame($case['reason'], $result->reason);
        }
    }

    public function test_invalid_money_and_line_input_is_rejected_without_float_math(): void
    {
        $coupon = Coupon::factory()->create();
        $this->expectException(InvalidArgumentException::class);
        app(EvaluateCoupon::class)->handle($coupon, [['product_id' => 1]], -1);
    }
}
