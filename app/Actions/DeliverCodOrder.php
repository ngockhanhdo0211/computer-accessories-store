<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DeliverCodOrder
{
    private const MAX_PRODUCT_QUANTITY = 4_294_967_295;

    public function __construct(private readonly ApplyDeliveredOrderMembershipSpending $membership) {}

    public function handle(string $orderCode, User $actor, string $eventKey, mixed $reason = null): Order
    {
        $this->assertActor($actor);
        $reason = is_string($reason) ? trim($reason) : $reason;
        $reason = $reason === '' ? null : $reason;
        $validated = Validator::make([
            'event_key' => is_string($eventKey) ? trim($eventKey) : $eventKey,
            'reason' => $reason,
        ], [
            'event_key' => ['required', 'uuid'],
            'reason' => ['nullable', 'string', 'max:500'],
        ])->validate();

        $identity = Order::query()->where('order_code', $orderCode)->firstOrFail(['id', 'user_id']);
        $deliveredAt = CarbonImmutable::now('UTC');

        try {
            return DB::transaction(function () use ($identity, $actor, $validated, $deliveredAt): Order {
                $users = User::query()->whereKey([$identity->user_id, $actor->getKey()])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $currentActor = $users->get($actor->getKey());
                $customer = $users->get($identity->user_id);
                if (! $currentActor instanceof User || ! $customer instanceof User) {
                    throw ValidationException::withMessages(['authorization' => 'Không tìm thấy tác nhân hoặc Customer của đơn hàng.']);
                }
                $this->assertActor($currentActor);
                if (UserRole::tryFrom((string) $customer->getRawOriginal('role')) !== UserRole::Customer) {
                    throw ValidationException::withMessages(['order' => 'Order không thuộc một Customer hợp lệ.']);
                }

                $order = Order::query()->lockForUpdate()->findOrFail($identity->id);
                $latest = OrderStatusHistory::query()->where('order_id', $order->id)
                    ->latest('created_at')->latest('id')->lockForUpdate()->first();
                if ($latest === null) {
                    throw ValidationException::withMessages(['order' => 'Đơn hàng thiếu lịch sử trạng thái.']);
                }
                $existing = OrderStatusHistory::query()->where('event_key', $validated['event_key'])->lockForUpdate()->first();
                if ($existing !== null) {
                    $this->assertReplay($order, $latest, $existing, $currentActor, $validated['reason'] ?? null);

                    return $order;
                }
                if ($latest->to_status !== $order->status) {
                    throw ValidationException::withMessages(['order' => 'Lịch sử trạng thái không khớp Order. Vui lòng đối soát trước khi giao.']);
                }
                if ($order->payment_method !== PaymentMethod::CashOnDelivery
                    || $order->payment_status !== PaymentStatus::Unpaid
                    || $order->status !== OrderStatus::InTransit
                    || $order->delivered_at !== null) {
                    throw ValidationException::withMessages(['order' => 'Chỉ đơn COD đang trung chuyển và chưa thanh toán mới được xác nhận giao.']);
                }

                $items = OrderItem::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
                if ($items->isEmpty()) {
                    throw ValidationException::withMessages(['order' => 'Đơn hàng không có sản phẩm để xác nhận giao.']);
                }
                $products = Product::query()->whereKey($items->pluck('product_id')->unique()->sort()->values())
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                foreach ($items as $item) {
                    $product = $products->get($item->product_id);
                    if (! $product instanceof Product || $item->quantity > self::MAX_PRODUCT_QUANTITY - $product->sold_quantity) {
                        throw ValidationException::withMessages(['inventory' => 'Số lượng đã bán vượt phạm vi lưu trữ của sản phẩm.']);
                    }
                    $product->forceFill(['sold_quantity' => $product->sold_quantity + $item->quantity])->save();
                }

                $order->forceFill([
                    'status' => OrderStatus::Delivered,
                    'payment_status' => PaymentStatus::Paid,
                    'delivered_at' => $deliveredAt,
                ])->save();
                (new OrderStatusHistory)->forceFill([
                    'order_id' => $order->id, 'from_status' => OrderStatus::InTransit, 'to_status' => OrderStatus::Delivered,
                    'actor_id' => $currentActor->id, 'reason' => $validated['reason'] ?? null,
                    'event_key' => $validated['event_key'], 'created_at' => $deliveredAt,
                ])->save();

                $this->membership->handle($order, $deliveredAt);
                (new AuditLog)->forceFill([
                    'actor_id' => $currentActor->id, 'action' => 'order.cod.delivered', 'subject_type' => Order::class,
                    'subject_id' => $order->id,
                    'before_json' => ['status' => OrderStatus::InTransit->value, 'payment_status' => PaymentStatus::Unpaid->value],
                    'after_json' => ['status' => OrderStatus::Delivered->value, 'payment_status' => PaymentStatus::Paid->value, 'delivered_at' => $deliveredAt->format('Y-m-d H:i:s.u'), 'reason' => $validated['reason'] ?? null],
                    'request_id' => $validated['event_key'], 'created_at' => $deliveredAt,
                ])->save();

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
            throw ValidationException::withMessages(['authorization' => 'Tài khoản không có quyền xác nhận giao đơn hàng.']);
        }
    }

    private function assertReplay(Order $order, OrderStatusHistory $latest, OrderStatusHistory $history, User $actor, ?string $reason): void
    {
        if ($history->order_id !== $order->id || $latest->id !== $history->id
            || $history->actor_id !== $actor->id || $history->from_status !== OrderStatus::InTransit
            || $history->to_status !== OrderStatus::Delivered || $history->reason !== $reason
            || $order->status !== OrderStatus::Delivered || $order->payment_status !== PaymentStatus::Paid
            || $order->delivered_at === null) {
            throw ValidationException::withMessages(['event_key' => 'Mã chống lặp đã được dùng cho một thao tác khác.']);
        }
        $audits = AuditLog::query()->where('request_id', $history->event_key)->lockForUpdate()->get();
        $audit = $audits->first();
        if ($audits->count() !== 1 || ! $audit instanceof AuditLog
            || $audit->action !== 'order.cod.delivered' || $audit->subject_type !== Order::class
            || $audit->subject_id !== $order->id || $audit->actor_id !== $actor->id) {
            throw ValidationException::withMessages(['order' => 'Audit của lần giao trước không còn nhất quán.']);
        }
    }

    private function isEventKeyConstraint(UniqueConstraintViolationException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'order_status_histories_event_unique')
            || str_contains($message, 'order_status_histories.event_key');
    }
}
