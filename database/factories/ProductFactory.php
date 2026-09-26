<?php

namespace Database\Factories;

use App\Enums\ProductVisibility;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);
        $price = fake()->numberBetween(100_000, 5_000_000);

        return [
            'category_id' => Category::factory(),
            'brand_id' => Brand::factory(),
            'sku' => 'SKU-'.fake()->unique()->numberBetween(100000, 999999),
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 999999),
            'name' => Str::title($name),
            'short_description' => fake()->sentence(),
            'description' => fake()->paragraph(),
            'price_vnd' => $price,
            'sale_price_vnd' => null,
            'visibility' => ProductVisibility::Active,
            'low_stock_threshold' => 5,
            'sellable_quantity' => 0,
            'damaged_quantity' => 0,
            'sold_quantity' => 0,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['visibility' => ProductVisibility::Active]);
    }

    public function hidden(): static
    {
        return $this->state(fn () => ['visibility' => ProductVisibility::Hidden]);
    }

    public function inStock(int $quantity = 10): static
    {
        return $this->state(fn () => ['sellable_quantity' => $quantity]);
    }
}
