<?php

namespace App\Actions;

use App\Enums\CouponUsageStatus;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\StockReservation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class ReleaseStockReservations
{
    public function __construct(private readonly ReleaseCouponUsage $couponUsages) {}

    public function handle(PaymentAttempt $attempt, ?CarbonInterface $at = null): int
    {
        $releasedAt = CarbonImmutable::instance($at ?? now())->utc();

        return DB::transaction(function () use ($attempt, $releasedAt): int {
            $lockedAttempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);

            if ($lockedAttempt->coupon_id !== null) {
                Coupon::query()->lockForUpdate()->findOrFail($lockedAttempt->coupon_id);
            }

            $productIds = StockReservation::query()
                ->where('payment_attempt_id', $lockedAttempt->id)
                ->orderBy('product_id')
                ->pluck('product_id');

            Product::query()
                ->whereKey($productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);

            $reservations = StockReservation::query()
                ->where('payment_attempt_id', $lockedAttempt->id)
                ->whereNull('released_at')
                ->whereNull('consumed_at')
                ->orderBy('product_id')
                ->lockForUpdate()
                ->get();

            foreach ($reservations as $reservation) {
                $reservation->released_at = $releasedAt;
                $reservation->save();
            }

            $usage = CouponUsage::query()
                ->where('payment_attempt_id', $lockedAttempt->id)
                ->lockForUpdate()
                ->first();
            if ($usage !== null && $usage->status === CouponUsageStatus::Reserved) {
                $this->couponUsages->releaseLocked($usage->id, 'expired', $releasedAt);
            }

            return $reservations->count();
        }, 3);
    }
}
