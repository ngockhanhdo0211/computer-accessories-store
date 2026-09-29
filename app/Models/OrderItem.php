<?php

namespace App\Models;

use App\ValueObjects\OrderItemSnapshot;
use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected static function booted(): void
    {
        static::creating(fn (OrderItem $item) => new OrderItemSnapshot(
            (string) $item->product_name,
            (string) $item->sku,
            (int) $item->quantity,
            (int) $item->unit_price_vnd,
            (int) $item->line_subtotal_vnd,
            (int) $item->discount_vnd,
            (int) $item->line_total_vnd,
        ));
        static::updating(fn () => throw new LogicException('Order items are immutable.'));
        static::deleting(fn () => throw new LogicException('Order items cannot be deleted.'));
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price_vnd' => 'integer',
            'line_subtotal_vnd' => 'integer',
            'discount_vnd' => 'integer',
            'line_total_vnd' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function inventoryTransactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class);
    }
}
