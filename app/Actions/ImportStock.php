<?php

namespace App\Actions;

use App\Actions\Concerns\HandlesInventory;
use App\Enums\InventoryTransactionType;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ImportStock
{
    use HandlesInventory;

    public function handle(Product $product, User $actor, int $quantity, string $reason, string $sourceKey): InventoryTransaction
    {
        $this->assertActor($actor);
        $this->validateMovement($quantity, $reason, $sourceKey);

        return DB::transaction(function () use ($product, $actor, $quantity, $reason, $sourceKey) {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            $existing = InventoryTransaction::query()->where('product_id', $locked->id)
                ->where('type', InventoryTransactionType::Import)->where('source_key', $sourceKey)->first();
            if ($existing) {
                if ($existing->sellable_delta !== $quantity || $existing->damaged_delta !== 0 || $existing->actor_id !== $actor->id || $existing->reason !== trim($reason)) {
                    throw ValidationException::withMessages(['request_key' => 'Mã chống lặp đã được dùng cho nội dung khác.']);
                }

                return $existing;
            }

            $before = $this->projection($locked);
            $locked->forceFill(['sellable_quantity' => $this->nextQuantity($locked->sellable_quantity, $quantity, 'quantity')])->save();
            $transaction = $this->createLedger($locked, $actor, InventoryTransactionType::Import, $quantity, 0, $sourceKey, $reason);
            $this->audit($actor, 'inventory.stock.imported', $transaction, $before, $this->projection($locked), $sourceKey);

            return $transaction;
        }, 3);
    }
}
