<?php

namespace App\ValueObjects;

use App\Enums\CouponScope;
use App\Enums\CouponType;
use InvalidArgumentException;

final readonly class CheckoutCouponSnapshot
{
    public function __construct(
        public int $couponId,
        public string $code,
        public string $type,
        public string $scope,
        public int $value,
        public int $eligibleSubtotalVnd,
    ) {
        $couponType = CouponType::tryFrom($type);

        if ($couponId < 1
            || trim($code) === ''
            || $couponType === null
            || CouponScope::tryFrom($scope) === null
            || $value < 0
            || $eligibleSubtotalVnd < 0
            || ($couponType === CouponType::Percent && ($value < 1 || $value > 100))
            || ($couponType === CouponType::Fixed && $value < 1)
            || ($couponType === CouponType::FreeShipping && $value !== 0)) {
            throw new InvalidArgumentException('Checkout coupon snapshot is invalid.');
        }
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'coupon_id' => $this->couponId,
            'code' => $this->code,
            'type' => $this->type,
            'scope' => $this->scope,
            'value' => $this->value,
            'eligible_subtotal_vnd' => $this->eligibleSubtotalVnd,
        ];
    }
}
