<?php

namespace App\Actions;

use App\Actions\Concerns\HandlesInventory;
use App\Enums\InventoryTransactionType;
use App\Models\InventoryAdjustmentRequest;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveStockAdjustment
{
    use HandlesInventory;

    public function handle(InventoryAdjustmentRequest $adjustment, User $reviewer): InventoryTransaction
    {
        $this->assertActor($reviewer, true);

        return DB::transaction(function () use ($adjustment, $reviewer) {
            $lockedRequest = InventoryAdjustmentRequest::query()->lockForUpdate()->findOrFail($adjustment->id);
            if ($lockedRequest->isApproved()) {
                return InventoryTransaction::query()->where('adjustment_request_id', $lockedRequest->id)->firstOrFail();
            }
            if ($lockedRequest->isRejected()) {
                throw ValidationException::withMessages(['adjustment' => 'Đề nghị đã bị từ chối và không thể duyệt.']);
            }
            $product = Product::query()->lockForUpdate()->findOrFail($lockedRequest->product_id);
            $before = ['sellable_quantity' => $product->sellable_quantity, 'damaged_quantity' => $product->damaged_quantity];
            $after = [
                'sellable_quantity' => $this->nextQuantity($product->sellable_quantity, $lockedRequest->sellable_delta, 'sellable_delta'),
                'damaged_quantity' => $this->nextQuantity($product->damaged_quantity, $lockedRequest->damaged_delta, 'damaged_delta'),
            ];
            $product->forceFill($after)->save();
            $lockedRequest->forceFill(['reviewed_by' => $reviewer->id, 'approved_at' => now()])->save();
            $transaction = $this->createLedger($product, $reviewer, InventoryTransactionType::ManualAdjustment,
                $lockedRequest->sellable_delta, $lockedRequest->damaged_delta, 'adjustment:'.$lockedRequest->id,
                $lockedRequest->reason, $lockedRequest);
            $this->audit($reviewer, 'inventory.adjustment.approved', $lockedRequest, $before, $after, $lockedRequest->request_key);

            return $transaction;
        }, 3);
    }
}
