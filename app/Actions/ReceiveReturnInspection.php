<?php

namespace App\Actions;

use App\Actions\Concerns\AuthorizesReturnInspection;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ReturnInspection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ReceiveReturnInspection
{
    use AuthorizesReturnInspection;

    public function handle(
        string $orderCode,
        int $orderItemId,
        User $actor,
        mixed $eventKey,
        ?string $note = null,
    ): ReturnInspection {
        $validated = Validator::make(['event_key' => is_string($eventKey) ? strtolower(trim($eventKey)) : $eventKey], [
            'event_key' => ['required', 'uuid'],
        ])->validate();
        $note = $this->normalizeInspectionNote($note);
        $orderId = (int) Order::query()->where('order_code', $orderCode)->firstOrFail(['id'])->id;
        $fingerprint = hash('sha256', json_encode([
            'operation' => 'return_inspection.receive', 'order_id' => $orderId, 'order_item_id' => $orderItemId,
            'actor_id' => (int) $actor->id, 'note' => $note,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($orderId, $orderItemId, $actor, $validated, $fingerprint, $note): ReturnInspection {
                $currentActor = User::query()->lockForUpdate()->find($actor->getKey());

                if ($currentActor === null) {
                    $this->deny();
                }
                [$order, $item] = $this->lockOrderAndItem($orderId, $orderItemId);
                $inspection = ReturnInspection::query()->where('order_item_id', $item->id)->lockForUpdate()->first();
                $this->assertReturnInspectionAccess($currentActor, $order);

                if ($inspection !== null) {
                    if ($inspection->receive_event_key === null) {
                        throw ValidationException::withMessages(['event_key' => 'Biên nhận lịch sử không có mã chống lặp; không thể giả lập replay.']);
                    }
                    if ($inspection->receive_event_key !== $validated['event_key'] || ! hash_equals((string) $inspection->receive_fingerprint, $fingerprint)) {
                        throw ValidationException::withMessages(['event_key' => 'Order Item đã được tiếp nhận bằng mã hoặc nội dung khác.']);
                    }
                    $this->assertReplayEvidence($inspection, $order, $currentActor, $validated['event_key'], $note);

                    return $inspection;
                }
                if (AuditLog::query()->where('request_id', $validated['event_key'])->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages(['event_key' => 'Mã chống lặp đã được dùng cho thao tác khác.']);
                }
                $receivedAt = CarbonImmutable::now('UTC');

                $inspection = new ReturnInspection;
                $inspection->forceFill([
                    'order_item_id' => $item->id,
                    'received_by' => $currentActor->id,
                    'received_at' => $receivedAt,
                    'inspected_by' => null,
                    'inspected_at' => null,
                    'sellable_quantity' => null,
                    'damaged_quantity' => null,
                    'note' => $note,
                    'receive_event_key' => $validated['event_key'],
                    'receive_fingerprint' => $fingerprint,
                    'complete_event_key' => null,
                    'complete_fingerprint' => null,
                ]);
                $inspection->save();

                (new AuditLog)->forceFill([
                    'actor_id' => $currentActor->id,
                    'action' => 'return_inspection.received',
                    'subject_type' => ReturnInspection::class,
                    'subject_id' => $inspection->id,
                    'before_json' => null,
                    'after_json' => [
                        'order_id' => $order->id,
                        'order_item_id' => $item->id,
                        'received_at' => $receivedAt->format('Y-m-d H:i:s.u'),
                        'note' => $note,
                    ],
                    'request_id' => $validated['event_key'],
                    'created_at' => now(),
                ])->save();

                return $inspection;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            if (str_contains($exception->getMessage(), 'receive_event')) {
                throw ValidationException::withMessages(['event_key' => 'Mã chống lặp đã được dùng cho biên nhận khác.']);
            }
            throw $exception;
        }
    }

    private function assertReplayEvidence(ReturnInspection $inspection, Order $order, User $actor, string $eventKey, ?string $note): void
    {
        $audits = AuditLog::query()->where('request_id', $eventKey)->lockForUpdate()->get();
        $audit = $audits->first();
        if ($audits->count() !== 1 || $audit === null || $audit->action !== 'return_inspection.received'
            || $audit->subject_type !== ReturnInspection::class || $audit->subject_id !== $inspection->id
            || $audit->actor_id !== $actor->id || $inspection->received_by !== $actor->id
            || ($audit->after_json['note'] ?? null) !== $note
            || (int) ($audit->after_json['order_id'] ?? 0) !== $order->id
            || (int) ($audit->after_json['order_item_id'] ?? 0) !== $inspection->order_item_id
            || ($audit->after_json['received_at'] ?? null) !== $inspection->received_at->format('Y-m-d H:i:s.u')) {
            throw ValidationException::withMessages(['audit' => 'Bằng chứng tiếp nhận không đầy đủ hoặc không còn khớp.']);
        }
    }

    /** @return array{Order, OrderItem} */
    private function lockOrderAndItem(int $orderId, int $orderItemId): array
    {
        $order = Order::query()->lockForUpdate()->findOrFail($orderId);
        $items = OrderItem::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
        $item = $items->first(fn (OrderItem $candidate): bool => $candidate->id === $orderItemId);

        if ($item === null) {
            throw ValidationException::withMessages([
                'order_item' => 'Order Item không thuộc Order đang xử lý.',
            ]);
        }

        return [$order, $item];
    }

    private function deny(): never
    {
        throw ValidationException::withMessages([
            'authorization' => 'Tài khoản không có quyền kiểm tra hàng hoàn ở trạng thái Order hiện tại.',
        ]);
    }
}
