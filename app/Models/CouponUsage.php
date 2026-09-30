<?php

namespace App\Models;

use App\Enums\CouponUsageStatus;
use Carbon\CarbonInterface;
use Database\Factories\CouponUsageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CouponUsage extends Model
{
    /** @use HasFactory<CouponUsageFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    private bool $allowsLifecycleTransition = false;

    protected static function booted(): void
    {
        static::updating(function (CouponUsage $usage): void {
            if ($usage->isDirty(['coupon_id', 'customer_id', 'payment_attempt_id', 'reserved_at', 'expires_at', 'created_at'])) {
                throw new LogicException('Coupon Usage identity and reservation timestamps are immutable.');
            }

            $allowed = ['status', 'order_id', 'consumed_at', 'released_at', 'release_reason', 'late_callback_exception', 'updated_at'];
            if (array_diff(array_keys($usage->getDirty()), $allowed) !== []) {
                throw new LogicException('Coupon Usage may only change through a supported lifecycle transition.');
            }

            if (! $usage->allowsLifecycleTransition) {
                throw new LogicException('Coupon Usage lifecycle may only change through a domain action.');
            }
        });

        static::deleting(fn () => throw new LogicException('Coupon Usages cannot be deleted.'));
    }

    protected function casts(): array
    {
        return [
            'status' => CouponUsageStatus::class,
            'reserved_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
            'late_callback_exception' => 'boolean',
        ];
    }

    public function transitionLifecycle(array $attributes): bool
    {
        $this->allowsLifecycleTransition = true;

        try {
            return $this->forceFill($attributes)->save();
        } finally {
            $this->allowsLifecycleTransition = false;
        }
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function paymentAttempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function scopeHoldingCapacityAt(Builder $query, CarbonInterface $at): Builder
    {
        return $query->where(function (Builder $capacity) use ($at): void {
            $capacity->where('status', CouponUsageStatus::Consumed)
                ->orWhere(function (Builder $reserved) use ($at): void {
                    $reserved->where('status', CouponUsageStatus::Reserved)
                        ->whereNull('consumed_at')->whereNull('released_at')
                        ->where('expires_at', '>', $at->format('Y-m-d H:i:s.u'));
                });
        });
    }

    public function isHoldingCapacityAt(CarbonInterface $at): bool
    {
        return $this->status === CouponUsageStatus::Consumed
            || ($this->status === CouponUsageStatus::Reserved
                && $this->consumed_at === null && $this->released_at === null
                && $this->expires_at?->isAfter($at));
    }
}
