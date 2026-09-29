<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\StockReservationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockReservation extends Model
{
    /** @use HasFactory<StockReservationFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected static function booted(): void
    {
        static::updating(function (StockReservation $reservation): void {
            if ($reservation->isDirty(['payment_attempt_id', 'product_id', 'quantity', 'expires_at'])) {
                throw new \LogicException('Stock Reservation identity, quantity and expiry are immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'expires_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
        ];
    }

    public function paymentAttempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function scopeActiveAt(Builder $query, CarbonInterface $at): Builder
    {
        return $query
            ->whereNull('released_at')
            ->whereNull('consumed_at')
            ->where('expires_at', '>', $at->format('Y-m-d H:i:s.u'));
    }

    public function isActiveAt(CarbonInterface $at): bool
    {
        return $this->released_at === null
            && $this->consumed_at === null
            && $this->expires_at->isAfter($at);
    }
}
