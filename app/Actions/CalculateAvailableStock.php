<?php

namespace App\Actions;

use App\Models\Product;
use App\Models\StockReservation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use OverflowException;

class CalculateAvailableStock
{
    public function forProduct(
        Product $product,
        ?int $activeReservedQuantity = null,
        ?CarbonInterface $at = null,
        bool $lockReservations = false,
    ): int {
        $activeReservedQuantity ??= $this->activeReservedByProduct(
            [$product->id],
            $at,
            $lockReservations,
        )[$product->id] ?? 0;

        if ($activeReservedQuantity < 0) {
            throw new InvalidArgumentException('Active reserved quantity cannot be negative.');
        }

        return max(0, $product->sellable_quantity - $activeReservedQuantity);
    }

    /**
     * @param  iterable<int, Product>  $products
     * @param  array<int, int>|null  $activeReservedByProduct
     * @return array<int, int>
     */
    public function forProducts(
        iterable $products,
        ?array $activeReservedByProduct = null,
        ?CarbonInterface $at = null,
        bool $lockReservations = false,
    ): array {
        $products = $products instanceof Collection ? $products : collect($products);
        $activeReservedByProduct ??= $this->activeReservedByProduct(
            $products->pluck('id')->map(fn ($id) => (int) $id)->all(),
            $at,
            $lockReservations,
        );
        $available = [];

        foreach ($products as $product) {
            $available[$product->id] = $this->forProduct(
                $product,
                $activeReservedByProduct[$product->id] ?? 0,
            );
        }

        return $available;
    }

    /**
     * @param  list<int>  $productIds
     * @return array<int, int>
     */
    public function activeReservedByProduct(
        array $productIds,
        ?CarbonInterface $at = null,
        bool $lockForUpdate = false,
    ): array {
        $productIds = array_values(array_unique(array_filter($productIds, fn (int $id) => $id > 0)));

        if ($productIds === []) {
            return [];
        }

        $activeAt = CarbonImmutable::instance($at ?? now());

        $query = StockReservation::query()
            ->select(['id', 'product_id', 'quantity'])
            ->whereIn('product_id', $productIds)
            ->activeAt($activeAt)
            ->orderBy('product_id')
            ->orderBy('id');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $reserved = [];

        foreach ($query->get() as $reservation) {
            $productId = (int) $reservation->product_id;
            $quantity = (int) $reservation->quantity;
            $current = $reserved[$productId] ?? 0;

            if ($quantity < 0 || $current > PHP_INT_MAX - $quantity) {
                throw new OverflowException('Active reserved quantity exceeds the supported integer range.');
            }

            $reserved[$productId] = $current + $quantity;
        }

        return $reserved;
    }
}
