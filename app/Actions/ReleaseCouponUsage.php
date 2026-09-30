<?php

namespace App\Actions;

use App\Enums\CouponUsageStatus;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\PaymentAttempt;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReleaseCouponUsage
{
    /** Coupon-only transition. Expiration flows that also own stock must use ReleaseStockReservations. */
    public function handle(CouponUsage $usage, string $reason, ?CarbonInterface $at = null): CouponUsage
    {
        $releasedAt = CarbonImmutable::instance($at ?? now())->utc();

        return DB::transaction(function () use ($usage, $reason, $releasedAt): CouponUsage {
            PaymentAttempt::query()->lockForUpdate()->findOrFail($usage->payment_attempt_id);
            Coupon::query()->lockForUpdate()->findOrFail($usage->coupon_id);

            return $this->releaseLocked($usage->id, $reason, $releasedAt);
        }, 3);
    }

    public function releaseLocked(int $usageId, string $reason, CarbonInterface $releasedAt): CouponUsage
    {
        $usage = CouponUsage::query()->lockForUpdate()->findOrFail($usageId);
        if ($usage->status === CouponUsageStatus::Released) {
            return $usage;
        }
        if ($usage->status !== CouponUsageStatus::Reserved) {
            throw ValidationException::withMessages(['coupon_usage' => 'Coupon Usage đã consumed nên không thể release.']);
        }
        $usage->transitionLifecycle([
            'status' => CouponUsageStatus::Released, 'released_at' => $releasedAt,
            'release_reason' => trim($reason) !== '' ? trim($reason) : 'released',
        ]);

        return $usage;
    }
}
