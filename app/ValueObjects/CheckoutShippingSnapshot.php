<?php

namespace App\ValueObjects;

use App\Enums\ShippingRegion;
use InvalidArgumentException;

final readonly class CheckoutShippingSnapshot
{
    public function __construct(
        public int $shippingRateId,
        public string $regionKey,
        public string $regionLabel,
        public int $shippingFeeVnd,
    ) {
        $region = ShippingRegion::tryFrom($regionKey);

        if ($shippingRateId < 1
            || $region === null
            || trim($regionLabel) === ''
            || $regionLabel !== $region->label()
            || $shippingFeeVnd < 0) {
            throw new InvalidArgumentException('Checkout shipping snapshot is invalid.');
        }
    }

    /** @return array{shipping_rate_id:int, region_key:string, region_label:string, shipping_fee_vnd:int} */
    public function toArray(): array
    {
        return [
            'shipping_rate_id' => $this->shippingRateId,
            'region_key' => $this->regionKey,
            'region_label' => $this->regionLabel,
            'shipping_fee_vnd' => $this->shippingFeeVnd,
        ];
    }
}
