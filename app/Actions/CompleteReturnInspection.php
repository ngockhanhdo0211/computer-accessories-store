<?php

namespace App\Actions;

use App\Actions\Concerns\AuthorizesReturnInspection;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ReturnInspection;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CompleteReturnInspection
{
    use AuthorizesReturnInspection;

    public function handle(
        string $orderCode,
        int $orderItemId,
        User $actor,
        mixed $eventKey,
        mixed $sellableQuantity,
        mixed $damagedQuantity,
        ?string $note = null,
    ): ReturnInspection {
        $validated = Validator::make(['event_key' => is_string($eventKey) ? strtolower(trim($eventKey)) : $eventKey], ['event_key' => ['required', 'uuid']])->validate();
        $note = $this->normalizeInspectionNote($note);
        if (! is_int($sellableQuantity) || ! is_int($damagedQuantity)
            || $sellableQuantity < 0 || $damagedQuantity < 0
            || $sellableQuantity > PHP_INT_MAX - $damagedQuantity) {
            throw ValidationException::withMessages([
                'quantity' => 'Số lượng phân loại phải là số nguyên không âm hợp lệ.',
            ]);
        }
        $orderId = (int) Order::query()->where('order_code', $orderCode)->firstOrFail(['id'])->id;

        try {
            return DB::transaction(function () use ($orderId, $orderItemId, $actor, $validated, $sellableQuantity, $damagedQuantity, $note): ReturnInspection {
                $currentActor = User::query()->lockForUpdate()->find($actor->getKey());

                if ($currentActor === null) {
                    $this->deny();
                }
                [$order, $item] = $this->lockOrderAndItem($orderId, $orderItemId);
                $inspection = ReturnInspection::query()->where('order_item_id', $item->id)->lockForUpdate()->first();
                $this->assertReturnInspectionAccess($currentActor, $order);
                if ($inspection === null) {
                    throw ValidationException::withMessages([
                        'order_item' => 'Order Item chưa được tiếp nhận để kiểm tra.',
                    ]);
                }

                $finalNote = $note ?? $inspection->note;
                $fingerprint = hash('sha256', json_encode([
                    'operation' => 'return_inspection.complete', 'inspection_id' => $inspection->id,
                    'order_item_id' => $item->id, 'actor_id' => $currentActor->id,
                    'sellable_quantity' => $sellableQuantity, 'damaged_quantity' => $damagedQuantity, 'note' => $finalNote,
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                if ($inspection->isCompleted()) {
                    if ($inspection->complete_event_key === null) {
                        throw ValidationException::withMessages(['event_key' => 'Kết quả lịch sử không có mã chống lặp; không thể giả lập replay.']);
                    }
                    if ($inspection->complete_event_key !== $validated['event_key'] || ! hash_equals((string) $inspection->complete_fingerprint, $fingerprint)) {
                        throw ValidationException::withMessages(['event_key' => 'Kết quả đã hoàn tất bằng mã hoặc nội dung khác.']);
                    }
                    $this->assertReplayEvidence($inspection, $order, $currentActor, $validated['event_key']);

                    return $inspection;
                }
                if (AuditLog::query()->where('request_id', $validated['event_key'])->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages(['event_key' => 'Mã chống lặp đã được dùng cho thao tác khác.']);
                }

                if ($sellableQuantity + $damagedQuantity !== $item->quantity) {
                    throw ValidationException::withMessages([
                        'quantity' => 'Tổng số lượng bán được và hư hỏng phải bằng số lượng Order Item.',
                    ]);
                }
                $inspectedAt = now()->toImmutable()->utc();
                if ($inspectedAt->lt($inspection->received_at)) {
                    throw ValidationException::withMessages([
                        'inspected_at' => 'Thời điểm kiểm tra không được trước thời điểm tiếp nhận.',
                    ]);
                }

                $previousNote = $inspection->note;
                $inspection->forceFill([
                    'inspected_by' => $currentActor->id,
                    'inspected_at' => $inspectedAt,
                    'sellable_quantity' => $sellableQuantity,
                    'damaged_quantity' => $damagedQuantity,
                    'note' => $finalNote,
                    'complete_event_key' => $validated['event_key'],
                    'complete_fingerprint' => $fingerprint,
                ])->save();

                (new AuditLog)->forceFill([
                    'actor_id' => $currentActor->id,
                    'action' => 'return_inspection.completed',
                    'subject_type' => ReturnInspection::class,
                    'subject_id' => $inspection->id,
                    'before_json' => [
                        'state' => 'pending',
                        'note' => $previousNote,
                    ],
                    'after_json' => [
                        'state' => 'completed',
                        'order_id' => $order->id,
                        'order_item_id' => $item->id,
                        'sellable_quantity' => $sellableQuantity,
                        'damaged_quantity' => $damagedQuantity,
                        'inspected_at' => $inspectedAt->format('Y-m-d H:i:s.u'),
                        'note' => $finalNote,
                    ],
                    'request_id' => $validated['event_key'],
                    'created_at' => now(),
                ])->save();

                return $inspection;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            if (str_contains($exception->getMessage(), 'complete_event')) {
                throw ValidationException::withMessages(['event_key' => 'Mã chống lặp đã được dùng cho kết quả khác.']);
            }
            throw $exception;
        }
    }

    private function assertReplayEvidence(ReturnInspection $inspection, Order $order, User $actor, string $eventKey): void
    {
        $audits = AuditLog::query()->where('request_id', $eventKey)->lockForUpdate()->get();
        $audit = $audits->first();
        if ($audits->count() !== 1 || $audit === null || $audit->action !== 'return_inspection.completed'
            || $audit->subject_type !== ReturnInspection::class || $audit->subject_id !== $inspection->id
            || $audit->actor_id !== $actor->id || $inspection->inspected_by !== $actor->id
            || ($audit->before_json['state'] ?? null) !== 'pending'
            || ($audit->after_json['state'] ?? null) !== 'completed'
            || (int) ($audit->after_json['order_id'] ?? 0) !== $order->id
            || (int) ($audit->after_json['order_item_id'] ?? 0) !== $inspection->order_item_id
            || ($audit->after_json['sellable_quantity'] ?? null) !== $inspection->sellable_quantity
            || ($audit->after_json['damaged_quantity'] ?? null) !== $inspection->damaged_quantity
            || ($audit->after_json['note'] ?? null) !== $inspection->note
            || ($audit->after_json['inspected_at'] ?? null) !== $inspection->inspected_at->format('Y-m-d H:i:s.u')) {
            throw ValidationException::withMessages(['audit' => 'Bằng chứng hoàn tất không đầy đủ hoặc không còn khớp.']);
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
