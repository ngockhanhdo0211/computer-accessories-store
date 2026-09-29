<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Database\Factories\OrderStatusHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

class OrderStatusHistory extends Model
{
    /** @use HasFactory<OrderStatusHistoryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected static function booted(): void
    {
        static::creating(function (OrderStatusHistory $history): void {
            $from = $history->from_status;
            $to = $history->to_status;

            if (! Str::isUuid((string) $history->event_key)
                || ($from === null && $to !== OrderStatus::Placed)
                || ($from !== null && ! $from->canTransitionTo($to))) {
                throw new LogicException('Order status history edge is invalid.');
            }
        });
        static::updating(fn () => throw new LogicException('Order status histories are append-only.'));
        static::deleting(fn () => throw new LogicException('Order status histories are append-only.'));
    }

    protected function casts(): array
    {
        return [
            'from_status' => OrderStatus::class,
            'to_status' => OrderStatus::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
