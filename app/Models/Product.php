<?php

namespace App\Models;

use App\Enums\ProductVisibility;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $fillable = [
        'category_id',
        'brand_id',
        'sku',
        'slug',
        'name',
        'short_description',
        'description',
        'price_vnd',
        'sale_price_vnd',
        'visibility',
    ];

    protected function casts(): array
    {
        return [
            'price_vnd' => 'integer',
            'sale_price_vnd' => 'integer',
            'low_stock_threshold' => 'integer',
            'sellable_quantity' => 'integer',
            'damaged_quantity' => 'integer',
            'sold_quantity' => 'integer',
            'visibility' => ProductVisibility::class,
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function primaryImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)
            ->where('is_primary', true)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query
            ->where('visibility', ProductVisibility::Active)
            ->whereHas('category', fn (Builder $category) => $category->where('is_visible', true))
            ->whereHas('brand', fn (Builder $brand) => $brand->where('is_visible', true));
    }

    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if ($search === null || $search === '') {
            return $query;
        }

        $literalSearch = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
        $pattern = '%'.$literalSearch.'%';

        return $query->where(function (Builder $searchQuery) use ($pattern) {
            $searchQuery->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("sku LIKE ? ESCAPE '!'", [$pattern]);
        });
    }

    public function scopeForCategory(Builder $query, ?int $categoryId): Builder
    {
        return $categoryId === null ? $query : $query->where('category_id', $categoryId);
    }

    public function scopeForBrand(Builder $query, ?int $brandId): Builder
    {
        return $brandId === null ? $query : $query->where('brand_id', $brandId);
    }

    public function effectivePriceVnd(): int
    {
        return $this->sale_price_vnd ?? $this->price_vnd;
    }

    public function formattedPrice(): string
    {
        return number_format($this->effectivePriceVnd(), 0, ',', '.').' ₫';
    }

    public function isInStock(): bool
    {
        return $this->sellable_quantity > 0;
    }
}
