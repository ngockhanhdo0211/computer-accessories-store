<?php

namespace Database\Factories;

use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\StockReservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockReservation> */
class StockReservationFactory extends Factory
{
    protected $model = StockReservation::class;

    public function definition(): array
    {
        return [
            'payment_attempt_id' => PaymentAttempt::factory(),
            'product_id' => Product::factory(),
            'quantity' => fake()->numberBetween(1, 5),
            'expires_at' => now()->addMinutes(15),
            'released_at' => null,
            'consumed_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subSecond()]);
    }

    public function released(): static
    {
        return $this->state(fn () => ['released_at' => now(), 'consumed_at' => null]);
    }
}
