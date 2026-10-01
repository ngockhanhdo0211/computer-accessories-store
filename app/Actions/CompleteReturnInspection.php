<?php

namespace App\Actions;

use App\Actions\Concerns\AuthorizesReturnInspection;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ReturnInspection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompleteReturnInspection
{
    use AuthorizesReturnInspection;

    public function handle(
        string $orderCode,
        int $orderItemId,
        User $actor,
        mixed $sellableQuantity,
        mixed $damagedQuantity,
        ?string $note = null,
    ): ReturnInspection {
        $note = $this->normalizeInspectionNote($note);
        if (! is_int($sellableQuantity) || ! is_int($damagedQuantity)
            || $sellableQuantity < 0 || $damagedQuantity < 0
            || $sellableQuantity > PHP_INT_MAX - $damagedQuantity) {
            throw ValidationException::withMessages([
                'quantity' => 'Số lượng phân loại phải là số nguyên không âm hợp lệ.',
            ]);
        }

        return DB::transaction(function () use ($orderCode, $orderItemId, $actor, $sellableQuantity, $damagedQuantity, $note): ReturnInspection {
            [$order, $item] = $this->lockOrderAndItem($orderCode, $orderItemId);
            $inspection = ReturnInspection::query()->where('order_item_id', $item->id)->lockForUpdate()->first();
            $currentActor = User::query()->sharedLock()->find($actor->getKey());

            if ($currentActor === null) {
                $this->deny();
            }
            $this->assertReturnInspectionAccess($currentActor, $order);
            if ($inspection === null) {
                throw ValidationException::withMessages([
                    'order_item' => 'Order Item chưa được tiếp nhận để kiểm tra.',
                ]);
            }

            $finalNote = $note ?? $inspection->note;
            if ($inspection->isCompleted()) {
                if ($inspection->inspected_by !== $currentActor->id
                    || $inspection->sellable_quantity !== $sellableQuantity
                    || $inspection->damaged_quantity !== $damagedQuantity
                    || $inspection->note !== $finalNote) {
                    throw ValidationException::withMessages([
                        'order_item' => 'Kết quả kiểm tra đã hoàn tất với nội dung khác.',
                    ]);
                }

                return $inspection;
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
