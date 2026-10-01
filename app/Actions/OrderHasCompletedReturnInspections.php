<?php

namespace App\Actions;

use App\Models\Order;
use App\Models\OrderItem;

class OrderHasCompletedReturnInspections
{
    public function handle(Order $order): bool
    {
        if (! $order->exists || $order->getKey() === null) {
            return false;
        }

        $items = $order->items()->with('returnInspection')->get();

        return $items->isNotEmpty() && $items->every(function (OrderItem $item): bool {
            $inspection = $item->returnInspection;

            return $inspection !== null
                && $inspection->isCompleted()
                && $inspection->sellable_quantity + $inspection->damaged_quantity === $item->quantity;
        });
    }
}
