<?php

namespace App\Actions;

use App\Enums\OrderCancellationRequestStatus;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SubmitOrderCancellationRequest
{
    public function handle(User $customer, string $orderCode, mixed $requestKey, mixed $reason): OrderCancellationRequest
    {
        $validated = Validator::make([
            'request_key' => is_string($requestKey) ? trim($requestKey) : $requestKey,
            'reason' => is_string($reason) ? trim($reason) : $reason,
        ], [
            'request_key' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:500', 'not_regex:/[<>\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u'],
        ])->validate();

        try {
            return DB::transaction(function () use ($customer, $orderCode, $validated): OrderCancellationRequest {
                $actor = User::query()->lockForUpdate()->find($customer->id);
                if ($actor === null
                    || UserRole::tryFrom((string) $actor->getRawOriginal('role')) !== UserRole::Customer
                    || UserStatus::tryFrom((string) $actor->getRawOriginal('status')) !== UserStatus::Active) {
                    throw ValidationException::withMessages(['authorization' => 'Tài khoản không thể gửi yêu cầu hủy đơn.']);
                }

                $order = Order::query()->where('user_id', $actor->id)->where('order_code', $orderCode)
                    ->lockForUpdate()->firstOrFail();
                $byKey = OrderCancellationRequest::query()->where('customer_id', $actor->id)
                    ->where('request_key', $validated['request_key'])->lockForUpdate()->first();
                if ($byKey !== null) {
                    if ($byKey->order_id !== $order->id || $byKey->reason !== $validated['reason']) {
                        throw ValidationException::withMessages(['request_key' => 'Mã chống lặp đã được dùng với nội dung khác.']);
                    }

                    return $byKey;
                }
                $existing = OrderCancellationRequest::query()->where('order_id', $order->id)->lockForUpdate()->first();
                if ($existing !== null) {
                    return $existing;
                }
                if ($order->status !== OrderStatus::Placed || $order->delivered_at !== null) {
                    throw ValidationException::withMessages(['order' => 'Chỉ có thể yêu cầu hủy đơn đang ở trạng thái Đã đặt.']);
                }

                $now = CarbonImmutable::now('UTC');
                $request = new OrderCancellationRequest;
                $request->forceFill([
                    'order_id' => $order->id, 'customer_id' => $actor->id,
                    'reason' => $validated['reason'], 'status' => OrderCancellationRequestStatus::Pending,
                    'reviewed_by' => null, 'review_note' => null, 'reviewed_at' => null,
                    'request_key' => $validated['request_key'], 'review_event_key' => null,
                    'review_fingerprint' => null, 'created_at' => $now, 'updated_at' => $now,
                ])->save();
                (new AuditLog)->forceFill([
                    'actor_id' => $actor->id, 'action' => 'order.cancellation_requested',
                    'subject_type' => OrderCancellationRequest::class, 'subject_id' => $request->id,
                    'before_json' => null, 'after_json' => ['order_id' => $order->id, 'status' => 'pending', 'reason' => $validated['reason']],
                    'request_id' => $validated['request_key'], 'created_at' => $now,
                ])->save();

                return $request;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            $byKey = OrderCancellationRequest::query()
                ->where('customer_id', $customer->id)
                ->where('request_key', $validated['request_key'])
                ->first();
            $orderId = Order::query()->where('user_id', $customer->id)->where('order_code', $orderCode)->value('id');
            if ($byKey !== null) {
                if ($byKey->order_id !== $orderId || $byKey->reason !== $validated['reason']) {
                    throw ValidationException::withMessages(['request_key' => 'Mã chá»‘ng láº·p Ä‘ã Ä‘Æ°á»£c dùng vá»›i ná»™i dung khác.']);
                }

                return $byKey;
            }

            $existing = OrderCancellationRequest::query()->where('order_id', function ($query) use ($customer, $orderCode): void {
                $query->select('id')->from('orders')->where('user_id', $customer->id)->where('order_code', $orderCode);
            })->first();
            if ($existing !== null) {
                return $existing;
            }
            throw ValidationException::withMessages(['request_key' => 'Mã chống lặp đã được sử dụng.']);
        }
    }
}
