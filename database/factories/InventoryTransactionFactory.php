<?php

namespace Database\Factories;

use App\Enums\InventoryTransactionType;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<InventoryTransaction> */
class InventoryTransactionFactory extends Factory
{
    public function definition(): array
    {
        return ['product_id' => Product::factory()->state(['sellable_quantity' => 1]), 'type' => InventoryTransactionType::Import,
            'sellable_delta' => 1, 'damaged_delta' => 0, 'source_key' => (string) Str::uuid(),
            'adjustment_request_id' => null, 'actor_id' => User::factory()->employee(), 'reason' => fake()->sentence()];
    }
}
