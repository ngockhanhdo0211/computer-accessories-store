<?php

namespace App\Models;

use App\Enums\ShippingRegion;
use Database\Factories\ShippingRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShippingRate extends Model
{
    /** @use HasFactory<ShippingRateFactory> */
    use HasFactory;

    protected $fillable = ['fee_vnd'];

    protected function casts(): array
    {
        return [
            'region_key' => ShippingRegion::class,
            'fee_vnd' => 'integer',
        ];
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function getRouteKeyName(): string
    {
        return 'region_key';
    }

    public function getRouteKey(): mixed
    {
        return $this->getRawOriginal('region_key');
    }
}
