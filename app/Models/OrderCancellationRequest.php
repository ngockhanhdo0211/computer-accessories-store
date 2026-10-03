<?php

namespace App\Models;

use App\Enums\OrderCancellationRequestStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class OrderCancellationRequest extends Model
{
    use HasFactory;

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    private bool $allowsReview = false;

    protected function casts(): array
    {
        return [
            'status' => OrderCancellationRequestStatus::class,
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $request): void {
            if ($request->isDirty(['order_id', 'customer_id', 'reason', 'request_key', 'created_at']) || ! $request->allowsReview) {
                throw new LogicException('Cancellation Request may only change through its review action.');
            }
        });
        static::deleting(fn () => throw new LogicException('Cancellation Requests cannot be deleted.'));
    }

    public function transitionReview(array $attributes): bool
    {
        $this->allowsReview = true;
        try {
            return $this->forceFill($attributes)->save();
        } finally {
            $this->allowsReview = false;
        }
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function inventoryTransactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class);
    }
}
