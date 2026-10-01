<?php

namespace Database\Factories;

use App\Models\OrderItem;
use App\Models\ReturnInspection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReturnInspection> */
class ReturnInspectionFactory extends Factory
{
    protected $model = ReturnInspection::class;

    public function definition(): array
    {
        return [
            'order_item_id' => OrderItem::factory(),
            'received_by' => User::factory()->admin(),
            'received_at' => now(),
            'inspected_by' => null,
            'inspected_at' => null,
            'sellable_quantity' => null,
            'damaged_quantity' => null,
            'note' => null,
        ];
    }
}
