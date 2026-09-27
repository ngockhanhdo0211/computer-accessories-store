<?php

namespace App\Actions;

use App\Actions\Concerns\HandlesInventory;
use App\Models\InventoryAdjustmentRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RejectStockAdjustment
{
    use HandlesInventory;

    public function handle(InventoryAdjustmentRequest $adjustment, User $reviewer): InventoryAdjustmentRequest
    {
        $this->assertActor($reviewer, true);

        return DB::transaction(function () use ($adjustment, $reviewer) {
            $locked = InventoryAdjustmentRequest::query()->lockForUpdate()->findOrFail($adjustment->id);
            if ($locked->isRejected()) {
                return $locked;
            }
            if ($locked->isApproved()) {
                throw ValidationException::withMessages(['adjustment' => 'Đề nghị đã được duyệt và không thể từ chối.']);
            }
            $locked->forceFill(['reviewed_by' => $reviewer->id, 'rejected_at' => now()])->save();
            $this->audit($reviewer, 'inventory.adjustment.rejected', $locked, ['status' => 'pending'], ['status' => 'rejected'], $locked->request_key);

            return $locked;
        }, 3);
    }
}
