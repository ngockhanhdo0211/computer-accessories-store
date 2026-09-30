<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Database\Factories\PaymentAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PaymentAttempt extends Model
{
    /** @use HasFactory<PaymentAttemptFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected static function booted(): void
    {
        static::updating(function (PaymentAttempt $attempt): void {
            $immutable = ['user_id', 'shipping_rate_id', 'request_key', 'gateway_reference', 'amount_vnd',
                'items_snapshot_json', 'recipient_snapshot_json', 'pricing_snapshot_json', 'shipping_fee_vnd',
                'coupon_id', 'expires_at'];

            if ($attempt->isDirty($immutable)) {
                throw new \LogicException('Payment Attempt identity and checkout snapshots are immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount_vnd' => 'integer',
            'items_snapshot_json' => 'array',
            'recipient_snapshot_json' => 'array',
            'pricing_snapshot_json' => 'array',
            'shipping_fee_vnd' => 'integer',
            'expires_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'late_callback_exception' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shippingRate(): BelongsTo
    {
        return $this->belongsTo(ShippingRate::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function stockReservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }

    public function order(): HasOne
    {
        return $this->hasOne(Order::class);
    }

    public function couponUsage(): HasOne
    {
        return $this->hasOne(CouponUsage::class);
    }
}
