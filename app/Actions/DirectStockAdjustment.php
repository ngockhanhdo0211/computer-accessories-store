<?php

namespace App\Actions;

use App\Actions\Concerns\HandlesInventory;
use App\Enums\InventoryTransactionType;
use App\Models\InventoryAdjustmentRequest;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DirectStockAdjustment
{
    use HandlesInventory;

    public function handle(Product $product, User $admin, int $sellableDelta, int $damagedDelta, string $reason, string $requestKey): InventoryTransaction
    {
        $this->assertActor($admin, true);
        $this->validateAdjustment($sellableDelta, $damagedDelta, $reason, $requestKey);

        try {
            return DB::transaction(function () use ($product, $admin, $sellableDelta, $damagedDelta, $reason, $requestKey) {
                $existing = InventoryAdjustmentRequest::query()->where('request_key', $requestKey)->lockForUpdate()->first();
                if ($existing) {
                    return $this->existingTransaction($existing, $product, $admin, $sellableDelta, $damagedDelta, $reason);
                }

                $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
                $before = $this->projection($locked);
                $after = [
                    'sellable_quantity' => $this->nextQuantity($locked->sellable_quantity, $sellableDelta, 'sellable_delta'),
                    'damaged_quantity' => $this->nextQuantity($locked->damaged_quantity, $damagedDelta, 'damaged_delta'),
                    'sold_quantity' => $locked->sold_quantity,
                ];
                $request = new InventoryAdjustmentRequest;
                $request->forceFill([
                    'product_id' => $locked->id,
                    'requested_by' => $admin->id,
                    'reviewed_by' => $admin->id,
                    'request_key' => $requestKey,
                    'sellable_delta' => $sellableDelta,
                    'damaged_delta' => $damagedDelta,
                    'reason' => trim($reason),
                    'approved_at' => now(),
                ])->save();
                $locked->forceFill([
                    'sellable_quantity' => $after['sellable_quantity'],
                    'damaged_quantity' => $after['damaged_quantity'],
                ])->save();
                $transaction = $this->createLedger($locked, $admin, InventoryTransactionType::ManualAdjustment,
                    $sellableDelta, $damagedDelta, 'adjustment:'.$request->id, $reason, $request);
                $this->audit($admin, 'inventory.adjustment.direct', $request, $before, $after, $requestKey);

                return $transaction;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            if (! $this->isRequestKeyViolation($exception)) {
                throw $exception;
            }
            $existing = InventoryAdjustmentRequest::query()->where('request_key', $requestKey)->first();
            if (! $existing) {
                throw $exception;
            }

            return $this->existingTransaction($existing, $product, $admin, $sellableDelta, $damagedDelta, $reason);
        }
    }

    private function existingTransaction(InventoryAdjustmentRequest $existing, Product $product, User $admin, int $sellableDelta, int $damagedDelta, string $reason): InventoryTransaction
    {
        if ($existing->product_id !== $product->id || $existing->requested_by !== $admin->id
            || $existing->sellable_delta !== $sellableDelta || $existing->damaged_delta !== $damagedDelta
            || $existing->reason !== trim($reason) || ! $existing->isApproved()) {
            throw ValidationException::withMessages(['request_key' => 'Mã chống lặp đã được dùng cho nội dung khác.']);
        }

        return InventoryTransaction::query()->where('adjustment_request_id', $existing->id)->firstOrFail();
    }

    private function isRequestKeyViolation(UniqueConstraintViolationException $exception): bool
    {
        $details = strtolower($exception->getMessage());

        return str_contains($details, 'inventory_adjustment_requests_request_key_unique')
            || str_contains($details, 'inventory_adjustment_requests.request_key');
    }
}
