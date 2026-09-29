<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrderItem> */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    public function definition(): array
    {
        $quantity = 2;
        $unitPrice = 100_000;
        $subtotal = $unitPrice * $quantity;
        $discount = 0;

        return [
            'order_id' => Order::factory(),
            'product_id' => Product::factory(),
            'product_name' => fake()->words(3, true),
            'sku' => 'ORDER-SKU-'.fake()->unique()->numberBetween(1000, 999999),
            'quantity' => $quantity,
            'unit_price_vnd' => $unitPrice,
            'line_subtotal_vnd' => $subtotal,
            'discount_vnd' => $discount,
            'line_total_vnd' => $subtotal - $discount,
        ];
    }
}
