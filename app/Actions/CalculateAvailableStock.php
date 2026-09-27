<?php

namespace App\Actions;

use App\Models\Product;
use InvalidArgumentException;

class CalculateAvailableStock
{
    public function forProduct(Product $product, int $activeReservedQuantity = 0): int
    {
        if ($activeReservedQuantity < 0) {
            throw new InvalidArgumentException('Active reserved quantity cannot be negative.');
        }

        return max(0, $product->sellable_quantity - $activeReservedQuantity);
    }

    /**
     * @param  iterable<int, Product>  $products
     * @param  array<int, int>  $activeReservedByProduct
     * @return array<int, int>
     */
    public function forProducts(iterable $products, array $activeReservedByProduct = []): array
    {
        $available = [];

        foreach ($products as $product) {
            $available[$product->id] = $this->forProduct(
                $product,
                $activeReservedByProduct[$product->id] ?? 0,
            );
        }

        return $available;
    }
}
