<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\ReturnInspectionFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

class ReturnInspection extends Model
{
    /** @use HasFactory<ReturnInspectionFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected static function booted(): void
    {
        static::creating(function (ReturnInspection $inspection): void {
            $inspection->assertShape();
            if ($inspection->isCompleted()) {
                throw new LogicException('Return Inspections must be created pending.');
            }
        });

        static::updating(function (ReturnInspection $inspection): void {
            if ($inspection->getRawOriginal('inspected_at') !== null
                || $inspection->isDirty(['order_item_id', 'received_by', 'received_at', 'receive_event_key', 'receive_fingerprint', 'created_at'])
                || ! $inspection->isCompleted()) {
                throw new LogicException('Return Inspection permits only pending to completed transition.');
            }

            $inspection->assertShape();
            $inspection->assertQuantitiesReconcile();
        });

        static::deleting(fn () => throw new LogicException('Return Inspections cannot be deleted.'));
    }

    protected function casts(): array
    {
        return [
            'received_at' => 'immutable_datetime',
            'inspected_at' => 'immutable_datetime',
            'sellable_quantity' => 'integer',
            'damaged_quantity' => 'integer',
        ];
    }

    protected function completed(): Attribute
    {
        return Attribute::get(fn (): bool => $this->isCompleted());
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }

    public function inventoryTransactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class);
    }

    public function isCompleted(): bool
    {
        return $this->inspected_by !== null
            && $this->inspected_at instanceof CarbonInterface
            && $this->sellable_quantity !== null
            && $this->damaged_quantity !== null;
    }

    private function assertShape(): void
    {
        $pending = $this->inspected_by === null
            && $this->inspected_at === null
            && $this->sellable_quantity === null
            && $this->damaged_quantity === null;

        if (! $pending && ! $this->isCompleted()) {
            throw new LogicException('Return Inspection has an invalid pending/completed shape.');
        }
        if ($this->note !== null && (! is_string($this->note) || mb_strlen($this->note) > 500)) {
            throw new LogicException('Return Inspection note is invalid.');
        }
        foreach ([['receive_event_key', 'receive_fingerprint'], ['complete_event_key', 'complete_fingerprint']] as [$key, $fingerprint]) {
            if (($this->{$key} === null) !== ($this->{$fingerprint} === null)
                || ($this->{$key} !== null && (! is_string($this->{$key}) || ! Str::isUuid($this->{$key}) || strtolower($this->{$key}) !== $this->{$key}))
                || ($this->{$fingerprint} !== null && (! is_string($this->{$fingerprint}) || preg_match('/^[0-9a-f]{64}$/D', $this->{$fingerprint}) !== 1))) {
                throw new LogicException('Return Inspection idempotency evidence is invalid.');
            }
        }
        if (! $this->isCompleted() && ($this->complete_event_key !== null || $this->complete_fingerprint !== null)) {
            throw new LogicException('Pending Return Inspection cannot have completion evidence.');
        }
        if ($this->isCompleted()
            && (! is_int($this->sellable_quantity) || $this->sellable_quantity < 0
                || ! is_int($this->damaged_quantity) || $this->damaged_quantity < 0
                || $this->inspected_at->lt($this->received_at))) {
            throw new LogicException('Return Inspection completion is invalid.');
        }
    }

    private function assertQuantitiesReconcile(): void
    {
        if (! $this->isCompleted()) {
            return;
        }

        $quantity = OrderItem::query()->whereKey($this->order_item_id)->value('quantity');
        if ($quantity === null || $this->sellable_quantity + $this->damaged_quantity !== (int) $quantity) {
            throw new LogicException('Return Inspection quantities must match Order Item quantity.');
        }
    }
}
