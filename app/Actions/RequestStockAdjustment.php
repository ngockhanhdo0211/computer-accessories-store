<?php

namespace App\Actions;

use App\Actions\Concerns\HandlesInventory;
use App\Models\InventoryAdjustmentRequest;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RequestStockAdjustment
{
    use HandlesInventory;

    public function handle(Product $product, User $actor, int $sellableDelta, int $damagedDelta, string $reason, string $requestKey): InventoryAdjustmentRequest
    {
        $this->assertActor($actor);
        $this->validateAdjustment($sellableDelta, $damagedDelta, $reason, $requestKey);

        try {
            return DB::transaction(function () use ($product, $actor, $sellableDelta, $damagedDelta, $reason, $requestKey) {
                $existing = InventoryAdjustmentRequest::query()->where('request_key', $requestKey)->lockForUpdate()->first();
                if ($existing) {
                    return $this->matchingRequest($existing, $product, $actor, $sellableDelta, $damagedDelta, $reason);
                }

                $lockedProduct = Product::query()->lockForUpdate()->findOrFail($product->id);
                $request = new InventoryAdjustmentRequest;
                $request->forceFill([
                    'product_id' => $lockedProduct->id,
                    'requested_by' => $actor->id,
                    'request_key' => $requestKey,
                    'sellable_delta' => $sellableDelta,
                    'damaged_delta' => $damagedDelta,
                    'reason' => trim($reason),
                ])->save();
                $this->audit($actor, 'inventory.adjustment.requested', $request, [], [
                    'product_id' => $lockedProduct->id,
                    'sellable_delta' => $sellableDelta,
                    'damaged_delta' => $damagedDelta,
                    'status' => 'pending',
                ], $requestKey);

                return $request;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            if (! $this->isRequestKeyViolation($exception)) {
                throw $exception;
            }
            $existing = InventoryAdjustmentRequest::query()->where('request_key', $requestKey)->first();
            if (! $existing) {
                throw $exception;
            }

            return $this->matchingRequest($existing, $product, $actor, $sellableDelta, $damagedDelta, $reason);
        }
    }

    private function matchingRequest(InventoryAdjustmentRequest $existing, Product $product, User $actor, int $sellableDelta, int $damagedDelta, string $reason): InventoryAdjustmentRequest
    {
        if ($existing->product_id !== $product->id || $existing->requested_by !== $actor->id
            || $existing->sellable_delta !== $sellableDelta || $existing->damaged_delta !== $damagedDelta
            || $existing->reason !== trim($reason)) {
            throw ValidationException::withMessages(['request_key' => 'Mã chống lặp đã được dùng cho nội dung khác.']);
        }

        return $existing;
    }

    private function isRequestKeyViolation(UniqueConstraintViolationException $exception): bool
    {
        $details = strtolower($exception->getMessage());

        return str_contains($details, 'inventory_adjustment_requests_request_key_unique')
            || str_contains($details, 'inventory_adjustment_requests.request_key');
    }
}
