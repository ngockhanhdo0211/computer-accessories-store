<?php

namespace App\Actions\Concerns;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Validation\ValidationException;

trait AuthorizesReturnInspection
{
    private function assertReturnInspectionAccess(User $actor, Order $order): void
    {
        if (! $this->returnInspectionAccessAllowed($actor, $order)) {
            throw ValidationException::withMessages([
                'authorization' => 'Tài khoản không có quyền kiểm tra hàng hoàn ở trạng thái Order hiện tại.',
            ]);
        }
    }

    protected function returnInspectionAccessAllowed(User $actor, Order $order): bool
    {
        $role = UserRole::tryFrom((string) $actor->getRawOriginal('role'));
        $status = UserStatus::tryFrom((string) $actor->getRawOriginal('status'));

        return $status === UserStatus::Active && match ($order->status) {
            OrderStatus::Placed, OrderStatus::AwaitingHandoff => in_array($role, [UserRole::Employee, UserRole::Admin], true),
            OrderStatus::InTransit => $role === UserRole::Admin,
            default => false,
        };
    }

    private function normalizeInspectionNote(?string $note): ?string
    {
        $note = $note === null ? null : trim($note);
        $note = $note === '' ? null : $note;

        if ($note !== null && mb_strlen($note) > 500) {
            throw ValidationException::withMessages([
                'note' => 'Ghi chú kiểm tra không được vượt quá 500 ký tự.',
            ]);
        }

        return $note;
    }
}
