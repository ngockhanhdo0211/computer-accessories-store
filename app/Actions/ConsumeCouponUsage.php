<?php

namespace App\Actions;

use App\Enums\CouponUsageStatus;
use App\Enums\PaymentStatus;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Order;
use App\Models\PaymentAttempt;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConsumeCouponUsage
{
    public function __construct(private readonly ReserveCouponUsage $capacity) {}

    /** Coupon-only transition; the future callback orchestrator remains responsible for stock consumption and sale ledger writes. */
    public function handle(CouponUsage $usage, Order $order, bool $lateCallback = false, ?CarbonInterface $at = null): CouponUsage
    {
        $consumedAt = CarbonImmutable::instance($at ?? now())->utc();

        return DB::transaction(function () use ($usage, $order, $lateCallback, $consumedAt): CouponUsage {
            $attempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($usage->payment_attempt_id);
            $coupon = Coupon::query()->lockForUpdate()->findOrFail($usage->coupon_id);
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $lockedUsage = CouponUsage::query()->lockForUpdate()->findOrFail($usage->id);

            if ($lockedUsage->status === CouponUsageStatus::Consumed) {
                if ($lockedUsage->order_id === $lockedOrder->id) {
                    return $lockedUsage;
                }
                throw ValidationException::withMessages(['coupon_usage' => 'Coupon Usage đã được consume bởi Order khác.']);
            }

            $attemptCouponSnapshot = is_array($attempt->pricing_snapshot_json)
                ? ($attempt->pricing_snapshot_json['coupon'] ?? null)
                : null;
            $orderCouponSnapshot = $lockedOrder->coupon_snapshot_json;
            if ($attempt->status !== PaymentStatus::Paid || $attempt->verified_at === null
                || $attempt->user_id !== $lockedUsage->customer_id || $attempt->coupon_id !== $lockedUsage->coupon_id
                || $lockedOrder->user_id !== $lockedUsage->customer_id || $lockedOrder->payment_attempt_id !== $attempt->id
                || $lockedOrder->coupon_id !== $lockedUsage->coupon_id
                || ! $this->couponSnapshotsMatch($attemptCouponSnapshot, $orderCouponSnapshot, $lockedUsage->coupon_id)) {
                throw ValidationException::withMessages(['coupon_usage' => 'Order, Payment Attempt, Customer hoặc Coupon snapshot không khớp.']);
            }

            if ($lateCallback) {
                if ($lockedUsage->status !== CouponUsageStatus::Released) {
                    throw ValidationException::withMessages(['coupon_usage' => 'Chỉ usage đã released mới dùng nhánh callback muộn.']);
                }
                $this->capacity->assertCapacityLocked($coupon, $lockedUsage->customer, $consumedAt, $lockedUsage->id);
            } elseif ($lockedUsage->status !== CouponUsageStatus::Reserved || ! $lockedUsage->expires_at?->isAfter($consumedAt)) {
                throw ValidationException::withMessages(['coupon_usage' => 'Coupon Usage không còn reserved hợp lệ để consume.']);
            }

            $lockedUsage->transitionLifecycle([
                'status' => CouponUsageStatus::Consumed, 'order_id' => $lockedOrder->id,
                'consumed_at' => $consumedAt, 'late_callback_exception' => $lateCallback,
            ]);

            return $lockedUsage;
        }, 3);
    }

    private function couponSnapshotsMatch(mixed $attemptSnapshot, mixed $orderSnapshot, int $couponId): bool
    {
        if (! is_array($attemptSnapshot) || ! is_array($orderSnapshot)) {
            return false;
        }

        foreach (['coupon_id', 'code', 'type', 'scope', 'value', 'eligible_subtotal_vnd'] as $field) {
            if (! array_key_exists($field, $attemptSnapshot)
                || ! array_key_exists($field, $orderSnapshot)
                || $attemptSnapshot[$field] !== $orderSnapshot[$field]) {
                return false;
            }
        }

        return $attemptSnapshot['coupon_id'] === $couponId;
    }
}
