<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundReason;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class CreatePendingRefund
{
    /** Caller owns the transaction and the Payment Attempt row lock. */
    public function handleLocked(
        PaymentAttempt $attempt,
        RefundReason $reason,
        CarbonInterface $createdAt,
    ): Refund {
        $existing = Refund::query()
            ->where('payment_attempt_id', $attempt->id)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            if ($existing->order_id !== null
                || $existing->amount_vnd !== $attempt->amount_vnd
                || $existing->reason !== $reason) {
                throw ValidationException::withMessages(['refund' => 'Refund hiện có xung đột với kết quả callback.']);
            }

            return $existing;
        }

        if ($attempt->status !== PaymentStatus::Paid
            || $attempt->verified_at === null
            || $attempt->gateway_transaction_id === null
            || $attempt->order()->exists()
            || $attempt->amount_vnd < 1) {
            throw ValidationException::withMessages(['refund' => 'Số tiền hoàn không hợp lệ.']);
        }

        $refund = new Refund;
        $refund->forceFill([
            'payment_attempt_id' => $attempt->id,
            'order_id' => null,
            'amount_vnd' => $attempt->amount_vnd,
            'reason' => $reason,
            'status' => RefundStatus::Pending,
            'gateway_refund_reference' => null,
            'note' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        return $refund;
    }

    public function handleCancelledOrderLocked(PaymentAttempt $attempt, Order $order, RefundReason $reason, CarbonInterface $createdAt): Refund
    {
        if ($reason !== RefundReason::CustomerCancellation) {
            throw ValidationException::withMessages(['refund' => 'Reason không hợp lệ cho Customer cancellation.']);
        }
        $existing = Refund::query()->where('payment_attempt_id', $attempt->id)->lockForUpdate()->first();
        if ($existing !== null) {
            if ($existing->order_id !== $order->id || $existing->amount_vnd !== $attempt->amount_vnd || $existing->reason !== $reason) {
                throw ValidationException::withMessages(['refund' => 'Refund hiện có xung đột với cancellation request.']);
            }

            return $existing;
        }
        if ($attempt->status !== PaymentStatus::Paid || $attempt->verified_at === null || $attempt->gateway_transaction_id === null
            || $order->payment_attempt_id !== $attempt->id || $order->status !== OrderStatus::Cancelled
            || $order->payment_status !== PaymentStatus::Paid || $attempt->amount_vnd !== $order->total_vnd) {
            throw ValidationException::withMessages(['refund' => 'Order VNPay chưa đủ điều kiện tạo Refund.']);
        }
        $refund = new Refund;
        $refund->forceFill(['payment_attempt_id' => $attempt->id, 'order_id' => $order->id, 'amount_vnd' => $attempt->amount_vnd,
            'reason' => $reason, 'status' => RefundStatus::Pending, 'gateway_refund_reference' => null, 'note' => null,
            'created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return $refund;
    }
}
