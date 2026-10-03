<?php

namespace App\Models;

use App\Enums\RefundGatewayAttemptStatus;
use Database\Factories\RefundGatewayAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class RefundGatewayAttempt extends Model
{
    /** @use HasFactory<RefundGatewayAttemptFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    private bool $allowsLifecycleTransition = false;

    protected static function booted(): void
    {
        static::updating(function (RefundGatewayAttempt $attempt): void {
            $immutable = [
                'refund_id', 'submitted_by', 'submission_event_key', 'request_id',
                'request_fingerprint', 'amount_vnd', 'submitted_at', 'created_at',
            ];
            if ($attempt->isDirty($immutable) || ! $attempt->allowsLifecycleTransition) {
                throw new LogicException('Refund Gateway Attempt may only change through its lifecycle actions.');
            }
        });
        static::deleting(fn () => throw new LogicException('Refund Gateway Attempts cannot be deleted.'));
    }

    protected function casts(): array
    {
        return [
            'status' => RefundGatewayAttemptStatus::class,
            'amount_vnd' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'reconciled_at' => 'immutable_datetime',
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

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reconciliationActor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }
}
