<?php

namespace App\Actions;

use App\Enums\CouponUsageStatus;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\ValueObjects\CheckoutQuote;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class ReserveCouponUsage
{
    /** Caller owns the transaction and has locked Attempt, Coupon, Products, Cart and active stock reservations in that order. */
    public function reserveLocked(Coupon $lockedCoupon, User $customer, PaymentAttempt $lockedAttempt, CheckoutQuote $quote, CarbonInterface $expiresAt, CarbonInterface $at, bool $capacityAlreadyChecked = false): CouponUsage
    {
        $existing = CouponUsage::query()->where('payment_attempt_id', $lockedAttempt->id)->lockForUpdate()->first();

        if ($existing !== null) {
            if ($existing->coupon_id !== $lockedCoupon->id || $existing->customer_id !== $customer->id || ! $existing->expires_at?->equalTo($expiresAt)) {
                throw ValidationException::withMessages(['request_key' => 'Payment Attempt đã giữ một Coupon Usage khác.']);
            }

            return $existing;
        }

        if ($quote->coupon?->couponId !== $lockedCoupon->id
            || $lockedAttempt->coupon_id !== $lockedCoupon->id
            || $lockedAttempt->user_id !== $customer->id
            || ! $lockedAttempt->expires_at->equalTo($expiresAt)) {
            throw ValidationException::withMessages(['coupon_code' => 'Coupon Usage không khớp snapshot Payment Attempt.']);
        }

        if (! $capacityAlreadyChecked) {
            $this->assertCapacityLocked($lockedCoupon, $customer, $at);
        }

        $usage = new CouponUsage;
        $usage->forceFill([
            'coupon_id' => $lockedCoupon->id, 'customer_id' => $customer->id,
            'payment_attempt_id' => $lockedAttempt->id, 'order_id' => null,
            'status' => CouponUsageStatus::Reserved, 'reserved_at' => $at,
            'expires_at' => $expiresAt, 'consumed_at' => null, 'released_at' => null,
            'release_reason' => null, 'late_callback_exception' => false,
        ])->save();

        return $usage;
    }

    public function assertCapacityLocked(Coupon $coupon, User $customer, CarbonInterface $at, ?int $exceptUsageId = null): void
    {
        $usages = CouponUsage::query()->where('coupon_id', $coupon->id)
            ->orderBy('customer_id')->orderBy('id')->lockForUpdate()->get();
        $holding = $usages->filter(fn (CouponUsage $usage) => $usage->id !== $exceptUsageId && $usage->isHoldingCapacityAt($at));
        $total = $holding->count();
        $forCustomer = $holding->where('customer_id', $customer->id)->count();

        if (($coupon->max_uses !== null && $total >= $coupon->max_uses)
            || ($coupon->max_uses_per_user !== null && $forCustomer >= $coupon->max_uses_per_user)) {
            throw ValidationException::withMessages(['coupon_code' => 'Mã giảm giá đã hết lượt sử dụng khả dụng.']);
        }
    }
}
