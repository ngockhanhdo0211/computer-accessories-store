<?php

namespace App\Actions;

use App\Enums\CouponScope;
use App\Enums\CouponType;
use App\Enums\MembershipLevel;
use App\Models\Coupon;
use App\ValueObjects\CouponEvaluation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

class EvaluateCoupon
{
    /**
     * Evaluate definition-only rules. Usage limits remain outside this slice.
     *
     * @param  array<int, array{product_id:int, category_id:int, brand_id:int, unit_price_vnd:int, quantity:int}>  $lines
     */
    public function handle(
        Coupon $coupon,
        array $lines,
        int $shippingFeeVnd,
        MembershipLevel $customerTier = MembershipLevel::Dong,
        ?CarbonInterface $at = null,
    ): CouponEvaluation {
        if ($shippingFeeVnd < 0) {
            throw new InvalidArgumentException('Shipping fee must be a non-negative VND integer.');
        }

        $at = CarbonImmutable::instance($at ?? now());

        if (! $coupon->is_active) {
            return $this->rejected('inactive');
        }
        if ($at->lt($coupon->starts_at)) {
            return $this->rejected('not_started');
        }
        if ($at->gt($coupon->ends_at)) {
            return $this->rejected('expired');
        }
        if ($coupon->required_tier !== null && $this->tierRank($customerTier) < $this->tierRank($coupon->required_tier)) {
            return $this->rejected('tier_not_eligible');
        }

        $targets = array_fill_keys($coupon->targetIds(), true);
        $eligibleSubtotal = 0;

        foreach ($lines as $line) {
            $this->assertLine($line);

            if ($this->lineMatchesScope($coupon->scope, $line, $targets)) {
                $lineSubtotal = $line['unit_price_vnd'] * $line['quantity'];
                if ($eligibleSubtotal > PHP_INT_MAX - $lineSubtotal) {
                    throw new InvalidArgumentException('Eligible subtotal exceeds integer range.');
                }
                $eligibleSubtotal += $lineSubtotal;
            }
        }

        if ($eligibleSubtotal === 0) {
            return $this->rejected('no_eligible_items');
        }
        if ($eligibleSubtotal < $coupon->min_subtotal_vnd) {
            return new CouponEvaluation(false, 'minimum_not_met', $eligibleSubtotal, 0, 0);
        }

        return match ($coupon->type) {
            CouponType::Percent => new CouponEvaluation(
                true,
                null,
                $eligibleSubtotal,
                $this->percentageOf($eligibleSubtotal, $coupon->value),
                0,
            ),
            CouponType::Fixed => new CouponEvaluation(
                true,
                null,
                $eligibleSubtotal,
                min($coupon->value, $eligibleSubtotal),
                0,
            ),
            CouponType::FreeShipping => new CouponEvaluation(
                true,
                null,
                $eligibleSubtotal,
                0,
                $shippingFeeVnd,
            ),
        };
    }

    private function rejected(string $reason): CouponEvaluation
    {
        return new CouponEvaluation(false, $reason, 0, 0, 0);
    }

    /** @param array<string, int> $line */
    private function assertLine(array $line): void
    {
        foreach (['product_id', 'category_id', 'brand_id', 'unit_price_vnd', 'quantity'] as $key) {
            if (! isset($line[$key]) || ! is_int($line[$key])) {
                throw new InvalidArgumentException("Coupon line {$key} must be an integer.");
            }
        }

        if ($line['product_id'] < 1 || $line['category_id'] < 1 || $line['brand_id'] < 1
            || $line['unit_price_vnd'] < 0 || $line['quantity'] < 1) {
            throw new InvalidArgumentException('Coupon line contains an invalid identifier, price, or quantity.');
        }

        if ($line['unit_price_vnd'] > intdiv(PHP_INT_MAX, $line['quantity'])) {
            throw new InvalidArgumentException('Coupon line subtotal exceeds integer range.');
        }
    }

    /** @param array<int, true> $targets */
    private function lineMatchesScope(CouponScope $scope, array $line, array $targets): bool
    {
        return match ($scope) {
            CouponScope::Cart => true,
            CouponScope::Product => isset($targets[$line['product_id']]),
            CouponScope::Category => isset($targets[$line['category_id']]),
            CouponScope::Brand => isset($targets[$line['brand_id']]),
        };
    }

    private function percentageOf(int $subtotal, int $percent): int
    {
        return intdiv($subtotal, 100) * $percent + intdiv(($subtotal % 100) * $percent, 100);
    }

    private function tierRank(MembershipLevel $tier): int
    {
        return match ($tier) {
            MembershipLevel::Dong => 0,
            MembershipLevel::Bac => 1,
            MembershipLevel::Vang => 2,
            MembershipLevel::KimCuong => 3,
        };
    }
}
