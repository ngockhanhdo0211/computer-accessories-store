<?php

namespace App\Models;

use Database\Factories\InventoryAdjustmentRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InventoryAdjustmentRequest extends Model
{
    /** @use HasFactory<InventoryAdjustmentRequestFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['sellable_delta' => 'integer', 'damaged_delta' => 'integer', 'approved_at' => 'datetime', 'rejected_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $request) {
            $alreadyProcessed = $request->getOriginal('approved_at') !== null || $request->getOriginal('rejected_at') !== null;
            $immutableChanged = $request->isDirty(['product_id', 'requested_by', 'request_key', 'sellable_delta', 'damaged_delta', 'reason']);
            $validDecision = $request->reviewed_by !== null
                && (($request->approved_at !== null) xor ($request->rejected_at !== null));

            if ($alreadyProcessed || $immutableChanged || ! $validDecision) {
                throw new \LogicException('Processed inventory adjustment requests are immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Inventory adjustment requests cannot be deleted.'));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function transaction(): HasOne
    {
        return $this->hasOne(InventoryTransaction::class, 'adjustment_request_id');
    }

    public function isPending(): bool
    {
        return $this->approved_at === null && $this->rejected_at === null;
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    public function isRejected(): bool
    {
        return $this->rejected_at !== null;
    }

    public function statusLabel(): string
    {
        return $this->isApproved() ? 'Đã duyệt' : ($this->isRejected() ? 'Đã từ chối' : 'Chờ duyệt');
    }
}
