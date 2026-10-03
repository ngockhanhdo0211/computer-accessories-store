<?php

namespace App\Models;

use App\Enums\RefundReason;
use App\Enums\RefundStatus;
use Database\Factories\RefundFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class Refund extends Model
{
    /** @use HasFactory<RefundFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected static function booted(): void
    {
        static::updating(function (Refund $refund): void {
            if ($refund->isDirty(['payment_attempt_id', 'order_id', 'amount_vnd', 'reason', 'created_at'])) {
                throw new LogicException('Refund identity, amount and reason are immutable.');
            }
            if ($refund->isDirty('status')) {
                $from = RefundStatus::tryFrom((string) $refund->getRawOriginal('status'));
                $to = $refund->status instanceof RefundStatus
                    ? $refund->status
                    : RefundStatus::tryFrom((string) $refund->status);
                if ($from !== RefundStatus::Pending
                    || ! in_array($to, [RefundStatus::Succeeded, RefundStatus::Failed], true)) {
                    throw new LogicException('Refund status may only leave pending once.');
                }
            }
        });
        static::deleting(fn () => throw new LogicException('Refunds cannot be deleted.'));
    }

    protected function casts(): array
    {
        return [
            'status' => RefundStatus::class,
            'reason' => RefundReason::class,
            'amount_vnd' => 'integer',
        ];
    }

    public function paymentAttempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
