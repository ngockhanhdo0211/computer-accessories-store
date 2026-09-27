<?php

namespace Database\Factories;

use App\Models\InventoryAdjustmentRequest;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<InventoryAdjustmentRequest> */
class InventoryAdjustmentRequestFactory extends Factory
{
    public function definition(): array
    {
        return ['product_id' => Product::factory(), 'requested_by' => User::factory()->employee(), 'reviewed_by' => null,
            'request_key' => (string) Str::uuid(), 'sellable_delta' => 1, 'damaged_delta' => 0,
            'reason' => fake()->sentence(), 'approved_at' => null, 'rejected_at' => null];
    }
}
