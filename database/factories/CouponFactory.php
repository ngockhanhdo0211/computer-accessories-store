<?php

namespace Database\Factories;

use App\Enums\CouponScope;
use App\Enums\CouponType;
use App\Models\Coupon;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Coupon> */
class CouponFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'SAVE'.fake()->unique()->numberBetween(1000, 999999),
            'type' => CouponType::Percent,
            'scope' => CouponScope::Cart,
            'value' => 10,
            'min_subtotal_vnd' => 0,
            'required_tier' => null,
            'max_uses' => null,
            'max_uses_per_user' => null,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'is_active' => true,
        ];
    }

    public function fixed(int $value = 50_000): static
    {
        return $this->state(fn () => ['type' => CouponType::Fixed, 'value' => $value]);
    }

    public function freeShipping(): static
    {
        return $this->state(fn () => ['type' => CouponType::FreeShipping, 'value' => 0]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
