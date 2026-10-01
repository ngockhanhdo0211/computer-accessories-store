<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TransitionOrderStatus
{
    public function handle(string $orderCode, User $actor, OrderStatus $target, string $eventKey, ?string $reason = null): Order
    {
        $this->assertActor($actor);

        $eventKey = trim($eventKey);
        $normalizedReason = $this->normalizeReason($reason);
        $validated = Validator::make([
            'event_key' => $eventKey,
            'reason' => $normalizedReason,
        ], [
            'event_key' => ['required', 'uuid'],
            'reason' => ['nullable', 'string', 'max:500'],
        ], [
            'event_key.required' => 'Thiếu mã chống lặp cho thao tác.',
            'event_key.uuid' => 'Mã chống lặp không hợp lệ.',
            'reason.string' => 'Ghi chú phải là chuỗi ký tự.',
            'reason.max' => 'Ghi chú không được vượt quá 500 ký tự.',
        ])->validate();
        $eventKey = $validated['event_key'];
        $normalizedReason = $validated['reason'] ?? null;

        return DB::transaction(function () use ($orderCode, $actor, $target, $eventKey, $normalizedReason): Order {
            $order = Order::query()
                ->where('order_code', $orderCode)
                ->lockForUpdate()
                ->firstOrFail();

            $currentActor = User::query()->sharedLock()->find($actor->getKey());
            if ($currentActor === null) {
                throw ValidationException::withMessages([
                    'authorization' => 'Tài khoản không có quyền cập nhật tiến trình vận chuyển.',
                ]);
            }
            $this->assertActor($currentActor);

            $current = $order->status;
            $latestHistory = OrderStatusHistory::query()
                ->where('order_id', $order->id)
                ->latest('created_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($latestHistory === null || $latestHistory->to_status !== $current) {
                throw ValidationException::withMessages([
                    'target_status' => 'Lịch sử trạng thái không khớp Order. Vui lòng đối soát trước khi tiếp tục.',
                ]);
            }

            $existing = OrderStatusHistory::query()
                ->where('order_id', $order->id)
                ->where('event_key', $eventKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $this->assertReplayMatches($existing, $currentActor, $target, $normalizedReason);

                return $order;
            }

            $expectedTarget = match ($current) {
                OrderStatus::Placed => OrderStatus::AwaitingHandoff,
                OrderStatus::AwaitingHandoff => OrderStatus::InTransit,
                default => null,
            };

            if ($expectedTarget !== $target) {
                throw ValidationException::withMessages([
                    'target_status' => 'Trạng thái đích không hợp lệ trong Order Transit Progression Phase 1.',
                ]);
            }

            $transitionedAt = now();
            $order->forceFill(['status' => $target])->save();

            (new OrderStatusHistory)->forceFill([
                'order_id' => $order->id,
                'from_status' => $current,
                'to_status' => $target,
                'actor_id' => $currentActor->id,
                'reason' => $normalizedReason,
                'event_key' => $eventKey,
                'created_at' => $transitionedAt,
            ])->save();

            (new AuditLog)->forceFill([
                'actor_id' => $currentActor->id,
                'action' => 'order.status.transitioned',
                'subject_type' => Order::class,
                'subject_id' => $order->id,
                'before_json' => ['status' => $current->value],
                'after_json' => [
                    'status' => $target->value,
                    'reason' => $normalizedReason,
                ],
                'request_id' => $eventKey,
                'created_at' => $transitionedAt,
            ])->save();

            return $order;
        }, 3);
    }

    private function assertActor(User $actor): void
    {
        $role = UserRole::tryFrom((string) $actor->getRawOriginal('role'));
        $status = UserStatus::tryFrom((string) $actor->getRawOriginal('status'));

        if (! in_array($role, [UserRole::Employee, UserRole::Admin], true) || $status !== UserStatus::Active) {
            throw ValidationException::withMessages([
                'authorization' => 'Tài khoản không có quyền cập nhật tiến trình vận chuyển.',
            ]);
        }
    }

    private function assertReplayMatches(OrderStatusHistory $history, User $actor, OrderStatus $target, ?string $reason): void
    {
        if ($history->actor_id !== $actor->id
            || $history->to_status !== $target
            || $history->reason !== $reason) {
            throw ValidationException::withMessages([
                'event_key' => 'Mã chống lặp đã được dùng cho một chuyển trạng thái khác.',
            ]);
        }
    }

    private function normalizeReason(?string $reason): ?string
    {
        $reason = $reason === null ? null : trim($reason);

        return $reason === '' ? null : $reason;
    }
}
