<?php

namespace App\Console\Commands;

use App\Actions\ReleaseStockReservations;
use App\Enums\PaymentStatus;
use App\Models\PaymentAttempt;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ReleaseExpiredStockReservations extends Command
{
    protected $signature = 'stock-reservations:release-expired {--batch=100 : Number of attempts processed per batch}';

    protected $description = 'Release expired VNPay stock reservations without changing physical inventory';

    public function handle(ReleaseStockReservations $release): int
    {
        $batchSize = filter_var($this->option('batch'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 1000],
        ]);

        if ($batchSize === false) {
            $this->error('The batch option must be an integer from 1 to 1000.');

            return self::INVALID;
        }

        $now = CarbonImmutable::instance(now())->utc();
        $released = 0;
        $attempts = 0;

        PaymentAttempt::query()
            ->where('status', PaymentStatus::Unpaid)
            ->where('expires_at', '<=', $now->format('Y-m-d H:i:s.u'))
            ->where(function ($query): void {
                $query->whereHas('stockReservations', fn ($reservations) => $reservations
                    ->whereNull('released_at')->whereNull('consumed_at'))
                    ->orWhereHas('couponUsage', fn ($usage) => $usage->where('status', 'reserved'));
            })
            ->orderBy('id')
            ->chunkById($batchSize, function ($expiredAttempts) use ($release, $now, &$released, &$attempts): void {
                foreach ($expiredAttempts as $attempt) {
                    $count = $release->handle($attempt, $now);
                    if ($count > 0) {
                        $attempts++;
                        $released += $count;
                    }
                }
            });

        $this->info("Released {$released} reservation(s) across {$attempts} payment attempt(s).");

        return self::SUCCESS;
    }
}
