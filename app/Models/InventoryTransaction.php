<?php

namespace App\Models;

use App\Enums\InventoryTransactionType;
use Database\Factories\InventoryTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class InventoryTransaction extends Model
{
    /** @use HasFactory<InventoryTransactionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['type' => InventoryTransactionType::class, 'sellable_delta' => 'integer', 'damaged_delta' => 'integer', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Inventory transactions are immutable.'));
        static::deleting(fn () => throw new LogicException('Inventory transactions are immutable.'));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function adjustmentRequest(): BelongsTo
    {
        return $this->belongsTo(InventoryAdjustmentRequest::class, 'adjustment_request_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function returnInspection(): BelongsTo
    {
        return $this->belongsTo(ReturnInspection::class);
    }

    public function orderCancellationRequest(): BelongsTo
    {
        return $this->belongsTo(OrderCancellationRequest::class);
    }
}
