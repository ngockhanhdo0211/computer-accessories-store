<?php

namespace Database\Factories;

use App\Enums\ShippingRegion;
use App\Models\ShippingRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ShippingRate> */
class ShippingRateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'region_key' => ShippingRegion::HaNoi,
            'fee_vnd' => 30_000,
            'updated_by' => null,
        ];
    }

    public function haNoi(): static
    {
        return $this->state(fn () => [
            'region_key' => ShippingRegion::HaNoi,
            'fee_vnd' => 30_000,
        ]);
    }

    public function other(): static
    {
        return $this->state(fn () => [
            'region_key' => ShippingRegion::Other,
            'fee_vnd' => 45_000,
        ]);
    }
}
