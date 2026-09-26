<?php

namespace App\Models;

use Database\Factories\ProductImageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    /** @use HasFactory<ProductImageFactory> */
    use HasFactory;

    protected $fillable = ['alt_text', 'is_primary', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function url(): string
    {
        $segments = array_map(rawurlencode(...), explode('/', $this->path));

        return '/storage/'.implode('/', $segments);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
