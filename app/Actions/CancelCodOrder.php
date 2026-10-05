<?php

namespace App\Actions;

use App\Actions\Concerns\GuardsPendingOrderCancellation;
use App\Enums\CouponUsageStatus;
use App\Enums\InventoryTransactionType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ReturnInspection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CancelCodOrder
{
    use GuardsPendingOrderCancellation;

    private const MAX_PRODUCT_QUANTITY = 4_294_967_295;

    public function __construct(private readonly OrderHasCompletedReturnInspections $readiness) {}

    public function handle(string $orderCode, User $actor, string $eventKey, mixed $reason): Order
    {
        $this->assertActor($actor);
        $validated = Validator::make([
            'event_key' => is_string($eventKey) ? trim($eventKey) : $eventKey,
            'reason' => is_string($reason) ? trim($reason) : $reason,
        ], [
            'event_key' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:500'],
        ])->validate();

        $identity = Order::query()->where('order_code', $orderCode)->firstOrFail(['id']);
        $cancelledAt = CarbonImmutable::now('UTC');

        try {
            return DB::transaction(function () use ($identity, $actor, $validated, $cancelledAt): Order {
                $currentActor = User::query()->lockForUpdate()->find($actor->getKey());
                if ($currentActor === null) {
                    throw ValidationException::withMessages(['authorization' => 'Tài khoản không có quyền hủy đơn hàng.']);
                }
                $this->assertActor($currentActor);

                $order = Order::query()->lockForUpdate()->findOrFail($identity->id);
                $history = $this->latestHistory($order);
                $existing = OrderStatusHistory::query()
                    ->where('event_key', $validated['event_key'])
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    $this->assertReplay($order, $history, $existing, $currentActor, $validated['reason']);

                    return $order;
                }

                $this->assertNoPendingCancellationRequest($order);
                $this->assertCancellationAllowed($order, $currentActor);
                if ($history->to_status !== $order->status) {
                    throw ValidationException::withMessages(['order' => 'Lịch sử trạng thái không khớp Order. Vui lòng đối soát trước khi hủy.']);
                }

                // Coupon precedes Product in every checkout/capacity writer. Keep the
                // same global order here so cancellation cannot deadlock a new order.
                $coupon = $order->coupon_id === null ? null : Coupon::query()->lockForUpdate()->find($order->coupon_id);

                $items = OrderItem::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
                if ($items->isEmpty()) {
                    throw ValidationException::withMessages(['order' => 'Đơn hàng không có sản phẩm để hoàn kho.']);
                }
                $products = Product::query()->whereKey($items->pluck('product_id')->unique()->sort()->values())
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $inspections = ReturnInspection::query()->whereIn('order_item_id', $items->pluck('id'))
                    ->orderBy('order_item_id')->lockForUpdate()->get()->keyBy('order_item_id');

                if (! $this->readiness->handle($order)) {
                    throw ValidationException::withMessages(['inspection' => 'Phải hoàn tất kiểm tra và phân loại toàn bộ sản phẩm trước khi hủy đơn.']);
                }
                $this->assertLockedInspections($items, $inspections);

                $usage = CouponUsage::query()->where('order_id', $order->id)->lockForUpdate()->first();
                $existingLedgers = InventoryTransaction::query()
                    ->whereIn('order_item_id', $items->pluck('id'))
                    ->where('type', InventoryTransactionType::CancelRestore)
                    ->orderBy('order_item_id')->lockForUpdate()->get();
                if ($existingLedgers->isNotEmpty()) {
                    throw ValidationException::withMessages(['order' => 'Đơn có dữ liệu hoàn kho nhưng chưa có lịch sử hủy tương ứng. Cần đối soát trước khi tiếp tục.']);
                }

                foreach ($items as $item) {
                    $inspection = $inspections->get($item->id);
                    $product = $products->get($item->product_id);
                    if (! $inspection instanceof ReturnInspection || ! $product instanceof Product) {
                        throw ValidationException::withMessages(['inspection' => 'Dữ liệu sản phẩm hoặc kiểm tra hàng hoàn không đầy đủ.']);
                    }

                    $sellable = $inspection->sellable_quantity;
                    $damaged = $inspection->damaged_quantity;
                    $nextSellable = $this->addQuantity($product->sellable_quantity, $sellable);
                    $nextDamaged = $this->addQuantity($product->damaged_quantity, $damaged);
                    $soldBefore = $product->sold_quantity;
                    $product->forceFill([
                        'sellable_quantity' => $nextSellable,
                        'damaged_quantity' => $nextDamaged,
                    ])->save();
                    if ($product->sold_quantity !== $soldBefore) {
                        throw new \LogicException('COD cancellation must not change sold quantity.');
                    }

                    (new InventoryTransaction)->forceFill([
                        'product_id' => $product->id,
                        'type' => InventoryTransactionType::CancelRestore,
                        'sellable_delta' => $sellable,
                        'damaged_delta' => $damaged,
                        'source_key' => 'order-item:'.$item->id.':cancel-restore',
                        'adjustment_request_id' => null,
                        'order_item_id' => $item->id,
                        'return_inspection_id' => $inspection->id,
                        'actor_id' => $currentActor->id,
                        'reason' => $validated['reason'],
                        'created_at' => $cancelledAt,
                    ])->save();
                }

                $from = $order->status;
                $order->forceFill(['status' => OrderStatus::Cancelled])->save();
                $this->releaseCoupon($order, $coupon, $usage, $cancelledAt);
                $this->recordHistoryAndAudit($order, $currentActor, $from, $validated['event_key'], $validated['reason'], $cancelledAt, $items);

                return $order;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            if (! $this->isEventKeyConstraint($exception)) {
                throw $exception;
            }

            throw ValidationException::withMessages(['event_key' => 'Mã chống lặp đã được dùng cho một Order khác.']);
        }
    }

    private function assertActor(User $actor): void
    {
        $role = UserRole::tryFrom((string) $actor->getRawOriginal('role'));
        $status = UserStatus::tryFrom((string) $actor->getRawOriginal('status'));
        if (! in_array($role, [UserRole::Employee, UserRole::Admin], true) || $status !== UserStatus::Active) {
            throw ValidationException::withMessages(['authorization' => 'Tài khoản không có quyền hủy đơn hàng.']);
        }
    }

    private function assertCancellationAllowed(Order $order, User $actor): void
    {
        if ($order->payment_method !== PaymentMethod::CashOnDelivery || $order->payment_status !== PaymentStatus::Unpaid
            || $order->delivered_at !== null) {
            throw ValidationException::withMessages(['order' => 'Slice hiện tại chỉ hỗ trợ hủy đơn COD chưa thanh toán.']);
        }
        if (! in_array($order->status, [OrderStatus::Placed, OrderStatus::AwaitingHandoff, OrderStatus::InTransit], true)) {
            throw ValidationException::withMessages(['order' => 'Trạng thái hiện tại không thể hủy.']);
        }
        if ($order->status === OrderStatus::InTransit
            && UserRole::tryFrom((string) $actor->getRawOriginal('role')) !== UserRole::Admin) {
            throw ValidationException::withMessages(['authorization' => 'Chỉ Admin được hủy đơn đang trung chuyển sau khi hàng quay lại và được kiểm tra.']);
        }
    }

    private function latestHistory(Order $order): OrderStatusHistory
    {
        $history = OrderStatusHistory::query()->where('order_id', $order->id)
            ->latest('created_at')->latest('id')->lockForUpdate()->first();
        if ($history === null) {
            throw ValidationException::withMessages(['order' => 'Đơn hàng thiếu lịch sử trạng thái.']);
        }

        return $history;
    }

    /** @param Collection<int, OrderItem> $items @param Collection<int, ReturnInspection> $inspections */
    private function assertLockedInspections(Collection $items, Collection $inspections): void
    {
        foreach ($items as $item) {
            $inspection = $inspections->get($item->id);
            if (! $inspection instanceof ReturnInspection || ! $inspection->isCompleted()
                || $inspection->sellable_quantity < 0 || $inspection->damaged_quantity < 0
                || $inspection->sellable_quantity + $inspection->damaged_quantity !== $item->quantity) {
                throw ValidationException::withMessages(['inspection' => 'Kết quả kiểm tra hàng hoàn không khớp số lượng Order Item.']);
            }
        }
    }

    private function releaseCoupon(Order $order, ?Coupon $coupon, ?CouponUsage $usage, CarbonImmutable $at): void
    {
        if ($order->coupon_id === null) {
            if ($usage !== null) {
                throw ValidationException::withMessages(['coupon_usage' => 'Đơn không dùng Coupon nhưng lại có Coupon Usage.']);
            }

            return;
        }
        if ($coupon === null || $usage === null || $usage->coupon_id !== $coupon->id
            || $usage->customer_id !== $order->user_id || $usage->payment_attempt_id !== null
            || $usage->status !== CouponUsageStatus::Consumed) {
            throw ValidationException::withMessages(['coupon_usage' => 'Coupon Usage COD không khớp hoặc không còn ở trạng thái consumed.']);
        }
        $usage->transitionLifecycle([
            'status' => CouponUsageStatus::Released,
            'released_at' => $at,
            'release_reason' => 'cod_order_cancelled',
        ]);
    }

    /** @param Collection<int, OrderItem> $items */
    private function recordHistoryAndAudit(Order $order, User $actor, OrderStatus $from, string $eventKey, string $reason, CarbonImmutable $at, Collection $items): void
    {
        (new OrderStatusHistory)->forceFill([
            'order_id' => $order->id, 'from_status' => $from, 'to_status' => OrderStatus::Cancelled,
            'actor_id' => $actor->id, 'reason' => $reason, 'event_key' => $eventKey, 'created_at' => $at,
        ])->save();
        (new AuditLog)->forceFill([
            'actor_id' => $actor->id, 'action' => 'order.cod.cancelled', 'subject_type' => Order::class,
            'subject_id' => $order->id,
            'before_json' => ['status' => $from->value, 'payment_status' => PaymentStatus::Unpaid->value],
            'after_json' => ['status' => OrderStatus::Cancelled->value, 'payment_status' => PaymentStatus::Unpaid->value, 'restored_items' => $items->count(), 'reason' => $reason],
            'request_id' => $eventKey, 'created_at' => $at,
        ])->save();
    }

    private function assertReplay(Order $order, OrderStatusHistory $latest, OrderStatusHistory $history, User $actor, string $reason): void
    {
        if ($history->order_id !== $order->id || $latest->id !== $history->id
            || $history->actor_id !== $actor->id || $history->from_status === null
            || $history->to_status !== OrderStatus::Cancelled || $history->reason !== $reason
            || $order->status !== OrderStatus::Cancelled || $order->payment_status !== PaymentStatus::Unpaid) {
            throw ValidationException::withMessages(['event_key' => 'Mã chống lặp đã được dùng cho một thao tác khác.']);
        }
        $audits = AuditLog::query()->where('request_id', $history->event_key)->lockForUpdate()->get();
        $audit = $audits->first();
        if ($audits->count() !== 1 || ! $audit instanceof AuditLog
            || $audit->action !== 'order.cod.cancelled' || $audit->subject_type !== Order::class
            || $audit->subject_id !== $order->id || $audit->actor_id !== $actor->id) {
            throw ValidationException::withMessages(['order' => 'Audit của lần hủy trước không còn nhất quán.']);
        }
        $items = $order->items()->with('returnInspection')->get();
        foreach ($items as $item) {
            $inspection = $item->returnInspection;
            $ledger = InventoryTransaction::query()->where('type', InventoryTransactionType::CancelRestore)
                ->where('order_item_id', $item->id)->first();
            if ($inspection === null || $ledger === null || $ledger->return_inspection_id !== $inspection->id
                || $ledger->sellable_delta !== $inspection->sellable_quantity
                || $ledger->damaged_delta !== $inspection->damaged_quantity) {
                throw ValidationException::withMessages(['order' => 'Dữ liệu hoàn kho của lần hủy trước không còn nhất quán.']);
            }
        }
        $usage = $order->couponUsage()->first();
        if (($order->coupon_id !== null && ($usage === null || $usage->status !== CouponUsageStatus::Released))
            || ($order->coupon_id === null && $usage !== null)) {
            throw ValidationException::withMessages(['coupon_usage' => 'Coupon Usage của lần hủy trước không còn nhất quán.']);
        }
    }

    private function addQuantity(int $current, int $delta): int
    {
        if ($current < 0 || $delta < 0 || $delta > self::MAX_PRODUCT_QUANTITY - $current) {
            throw ValidationException::withMessages(['inventory' => 'Số lượng hoàn kho vượt phạm vi lưu trữ của sản phẩm.']);
        }

        return $current + $delta;
    }

    private function isEventKeyConstraint(UniqueConstraintViolationException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'order_status_histories_event_unique')
            || str_contains($message, 'order_status_histories.event_key');
    }
}
