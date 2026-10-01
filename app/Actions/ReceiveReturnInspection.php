<?php

namespace App\Actions;

use App\Actions\Concerns\AuthorizesReturnInspection;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ReturnInspection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReceiveReturnInspection
{
    use AuthorizesReturnInspection;

    public function handle(
        string $orderCode,
        int $orderItemId,
        User $actor,
        CarbonInterface $receivedAt,
        ?string $note = null,
    ): ReturnInspection {
        $note = $this->normalizeInspectionNote($note);
        $receivedAt = CarbonImmutable::instance($receivedAt)->utc();

        return DB::transaction(function () use ($orderCode, $orderItemId, $actor, $receivedAt, $note): ReturnInspection {
            [$order, $item] = $this->lockOrderAndItem($orderCode, $orderItemId);
            $inspection = ReturnInspection::query()->where('order_item_id', $item->id)->lockForUpdate()->first();
            $currentActor = User::query()->sharedLock()->find($actor->getKey());

            if ($currentActor === null) {
                $this->deny();
            }
            $this->assertReturnInspectionAccess($currentActor, $order);

            if ($inspection !== null) {
                $receivedNote = $inspection->note;
                if ($inspection->isCompleted()) {
                    $receiveAudit = AuditLog::query()
                        ->where('action', 'return_inspection.received')
                        ->where('subject_type', ReturnInspection::class)
                        ->where('subject_id', $inspection->id)
                        ->oldest('id')
                        ->first();
                    if ($receiveAudit === null) {
                        throw ValidationException::withMessages([
                            'order_item' => 'Biên nhận hàng hoàn thiếu audit gốc và cần được đối soát.',
                        ]);
                    }
                    $receivedNote = $receiveAudit->after_json['note'] ?? null;
                }

                if ($inspection->received_by !== $currentActor->id
                    || ! $inspection->received_at->equalTo($receivedAt)
                    || $receivedNote !== $note) {
                    throw ValidationException::withMessages([
                        'order_item' => 'Order Item đã có biên nhận hàng hoàn với nội dung khác.',
                    ]);
                }

                return $inspection;
            }

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
                'request_id' => null,
                'created_at' => now(),
            ])->save();

            return $inspection;
        }, 3);
    }

    /** @return array{Order, OrderItem} */
    private function lockOrderAndItem(string $orderCode, int $orderItemId): array
    {
        $order = Order::query()->where('order_code', $orderCode)->lockForUpdate()->firstOrFail();
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
