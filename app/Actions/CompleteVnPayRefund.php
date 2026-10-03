<?php

namespace App\Actions;

use App\Enums\CouponUsageStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use App\Models\RefundGatewayAttempt;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class CompleteVnPayRefund
{
    /** Caller owns locks for Refund, Gateway Attempt and Payment Attempt. */
    public function handleLocked(
        Refund $refund,
        RefundGatewayAttempt $gatewayAttempt,
        PaymentAttempt $paymentAttempt,
        bool $succeeded,
        CarbonInterface $at,
    ): void {
        if ($refund->payment_attempt_id !== $paymentAttempt->id
            || $gatewayAttempt->refund_id !== $refund->id
            || $refund->amount_vnd !== $paymentAttempt->amount_vnd
            || $paymentAttempt->status !== PaymentStatus::Paid
            || $refund->status !== RefundStatus::Pending) {
            throw ValidationException::withMessages(['refund' => 'Refund, Payment Attempt hoặc gateway evidence không còn nhất quán.']);
        }
        $linkedOrderId = $paymentAttempt->order()->value('id');
        if (($refund->order_id === null && $linkedOrderId !== null)
            || ($refund->order_id !== null && $linkedOrderId !== $refund->order_id)) {
            throw ValidationException::withMessages(['refund' => 'Order của Refund không thuộc Payment Attempt tương ứng.']);
        }

        $refund->forceFill(['status' => $succeeded ? RefundStatus::Succeeded : RefundStatus::Failed])->save();
        if (! $succeeded) {
            return;
        }

        $paymentAttempt->markRefunded();
        $this->releaseCouponLocked($paymentAttempt, $refund, $at);
    }

    private function releaseCouponLocked(PaymentAttempt $paymentAttempt, Refund $refund, CarbonInterface $at): void
    {
        if ($paymentAttempt->coupon_id !== null) {
            Coupon::query()->lockForUpdate()->findOrFail($paymentAttempt->coupon_id);
        }
        $usage = CouponUsage::query()->where('payment_attempt_id', $paymentAttempt->id)->lockForUpdate()->first();
        if ($paymentAttempt->coupon_id === null) {
            if ($usage !== null) {
                throw ValidationException::withMessages(['coupon_usage' => 'Payment Attempt không dùng Coupon nhưng có Coupon Usage.']);
            }

            return;
        }
        if ($usage === null || $usage->coupon_id !== $paymentAttempt->coupon_id
            || $usage->customer_id !== $paymentAttempt->user_id
            || ($refund->order_id !== null && $usage->order_id !== $refund->order_id)) {
            throw ValidationException::withMessages(['coupon_usage' => 'Coupon Usage không khớp Refund.']);
        }
        if ($usage->status === CouponUsageStatus::Released) {
            return;
        }
        if ($usage->status !== CouponUsageStatus::Consumed || $usage->order_id === null) {
            throw ValidationException::withMessages(['coupon_usage' => 'Coupon Usage chưa ở trạng thái có thể release sau Refund.']);
        }
        $usage->transitionLifecycle([
            'status' => CouponUsageStatus::Released,
            'released_at' => $usage->released_at ?? $at,
            'release_reason' => 'vnpay_refund_succeeded',
        ]);
    }
}
