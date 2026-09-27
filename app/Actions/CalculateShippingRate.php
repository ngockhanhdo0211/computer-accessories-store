<?php

namespace App\Actions;

use App\Enums\ShippingRegion;
use App\Exceptions\ShippingRateUnavailable;
use App\Models\ShippingRate;

class CalculateShippingRate
{
    /**
     * @return array{
     *     rate: ShippingRate,
     *     fee_vnd: int,
     *     snapshot: array{
     *         shipping_rate_id: int,
     *         region_key: string,
     *         region_label: string,
     *         shipping_fee_vnd: int
     *     }
     * }
     */
    public function handle(mixed $region): array
    {
        if ($region instanceof ShippingRegion) {
            $normalizedRegion = $region;
        } elseif (is_string($region)) {
            $normalizedRegion = ShippingRegion::tryFrom(strtolower(trim($region)));
        } else {
            $normalizedRegion = null;
        }

        if ($normalizedRegion === null) {
            throw new ShippingRateUnavailable('Khu vực giao hàng không hợp lệ.');
        }

        $rate = ShippingRate::query()->where('region_key', $normalizedRegion->value)->first();

        if ($rate === null) {
            throw new ShippingRateUnavailable("Chưa cấu hình phí vận chuyển cho khu vực {$normalizedRegion->label()}.");
        }

        $feeVnd = $rate->fee_vnd;

        return [
            'rate' => $rate,
            'fee_vnd' => $feeVnd,
            'snapshot' => [
                'shipping_rate_id' => $rate->id,
                'region_key' => $normalizedRegion->value,
                'region_label' => $normalizedRegion->label(),
                'shipping_fee_vnd' => $feeVnd,
            ],
        ];
    }
}
