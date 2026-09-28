<?php

namespace App\ValueObjects;

final readonly class CouponEvaluation
{
    public function __construct(
        public bool $eligible,
        public ?string $reason,
        public int $eligibleSubtotalVnd,
        public int $itemDiscountVnd,
        public int $shippingDiscountVnd,
    ) {}

    public function totalDiscountVnd(): int
    {
        return $this->itemDiscountVnd + $this->shippingDiscountVnd;
    }
}
