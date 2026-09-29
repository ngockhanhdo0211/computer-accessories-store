<?php

namespace App\Actions;

use App\Models\PaymentAttempt;
use App\Models\StockReservation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class ReleaseStockReservations
{
    public function handle(PaymentAttempt $attempt, ?CarbonInterface $at = null): int
    {
        $releasedAt = CarbonImmutable::instance($at ?? now())->utc();

        return DB::transaction(function () use ($attempt, $releasedAt): int {
            $lockedAttempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);

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

            return $reservations->count();
        }, 3);
    }
}
