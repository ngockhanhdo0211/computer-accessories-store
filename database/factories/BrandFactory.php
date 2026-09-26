<?php

namespace Database\Factories;

use App\Models\Brand;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Brand> */
class BrandFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'is_visible' => true,
        ];
    }

    public function visible(): static
    {
        return $this->state(fn (array $attributes) => ['is_visible' => true]);
    }

    public function hidden(): static
    {
        return $this->state(fn (array $attributes) => ['is_visible' => false]);
    }
}
