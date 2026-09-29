<?php

namespace App\Models;

use App\Enums\CouponScope;
use App\Enums\CouponType;
use App\Enums\MembershipLevel;
use Database\Factories\CouponFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    /** @use HasFactory<CouponFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'type',
        'scope',
        'value',
        'min_subtotal_vnd',
        'required_tier',
        'max_uses',
        'max_uses_per_user',
        'starts_at',
        'ends_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => CouponType::class,
            'scope' => CouponScope::class,
            'value' => 'integer',
            'min_subtotal_vnd' => 'integer',
            'required_tier' => MembershipLevel::class,
            'max_uses' => 'integer',
            'max_uses_per_user' => 'integer',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'is_active' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'coupon_products');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'coupon_categories');
    }

    public function brands(): BelongsToMany
    {
        return $this->belongsToMany(Brand::class, 'coupon_brands');
    }

    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return array<int, int> */
    public function targetIds(): array
    {
        return match ($this->scope) {
            CouponScope::Cart => [],
            CouponScope::Product => $this->products()->pluck('products.id')->map(fn ($id) => (int) $id)->all(),
            CouponScope::Category => $this->categories()->pluck('categories.id')->map(fn ($id) => (int) $id)->all(),
            CouponScope::Brand => $this->brands()->pluck('brands.id')->map(fn ($id) => (int) $id)->all(),
        };
    }
}
