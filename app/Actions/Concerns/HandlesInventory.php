<?php

namespace App\Actions\Concerns;

use App\Enums\InventoryTransactionType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\InventoryAdjustmentRequest;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

trait HandlesInventory
{
    protected function assertActor(User $actor, bool $adminOnly = false): void
    {
        $role = UserRole::tryFrom((string) $actor->getRawOriginal('role'));
        $status = UserStatus::tryFrom((string) $actor->getRawOriginal('status'));
        $allowed = $adminOnly ? [UserRole::Admin] : [UserRole::Employee, UserRole::Admin];

        if ($status !== UserStatus::Active || ! in_array($role, $allowed, true)) {
            throw ValidationException::withMessages(['authorization' => 'Tài khoản không có quyền thực hiện thao tác kho này.']);
        }
    }

    protected function validateMovement(int $quantity, string $reason, string $sourceKey): array
    {
        return Validator::make(compact('quantity', 'reason', 'sourceKey'), [
            'quantity' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'reason' => ['required', 'string', 'max:255', 'not_regex:/^\s*$/u'],
            'sourceKey' => ['required', 'uuid'],
        ])->validate();
    }

    protected function validateAdjustment(int $sellableDelta, int $damagedDelta, string $reason, string $requestKey): array
    {
        $validated = Validator::make(compact('sellableDelta', 'damagedDelta', 'reason', 'requestKey'), [
            'sellableDelta' => ['required', 'integer', 'between:-2147483648,2147483647'],
            'damagedDelta' => ['required', 'integer', 'between:-2147483648,2147483647'],
            'reason' => ['required', 'string', 'max:500', 'not_regex:/^\s*$/u'],
            'requestKey' => ['required', 'uuid'],
        ])->validate();

        if ($sellableDelta === 0 && $damagedDelta === 0) {
            throw ValidationException::withMessages(['sellableDelta' => 'Ít nhất một chênh lệch phải khác 0.']);
        }

        return $validated;
    }

    protected function nextQuantity(int $current, int $delta, string $field): int
    {
        $next = $current + $delta;
        if ($next < 0 || $next > 4_294_967_295) {
            throw ValidationException::withMessages([$field => 'Điều chỉnh làm số lượng kho vượt phạm vi hợp lệ.']);
        }

        return $next;
    }

    protected function createLedger(Product $product, User $actor, InventoryTransactionType $type, int $sellableDelta, int $damagedDelta, string $sourceKey, string $reason, ?InventoryAdjustmentRequest $request = null): InventoryTransaction
    {
        $transaction = new InventoryTransaction;
        $transaction->forceFill([
            'product_id' => $product->id, 'type' => $type, 'sellable_delta' => $sellableDelta,
            'damaged_delta' => $damagedDelta, 'source_key' => $sourceKey,
            'adjustment_request_id' => $request?->id, 'actor_id' => $actor->id,
            'reason' => mb_substr(trim($reason), 0, 255), 'created_at' => now(),
        ])->save();

        return $transaction;
    }

    protected function audit(User $actor, string $action, Model $subject, array $before, array $after, string $requestKey): void
    {
        (new AuditLog)->forceFill([
            'actor_id' => $actor->id,
            'action' => $action,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'before_json' => $before,
            'after_json' => $after,
            'request_id' => $requestKey,
            'created_at' => now(),
        ])->save();
    }

    protected function projection(Product $product): array
    {
        return [
            'sellable_quantity' => $product->sellable_quantity,
            'damaged_quantity' => $product->damaged_quantity,
            'sold_quantity' => $product->sold_quantity,
        ];
    }
}
