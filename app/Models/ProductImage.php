<?php

namespace App\Models;

use App\ValueObjects\StoredProductImage;
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
            'width' => 'integer',
            'height' => 'integer',
            'bytes' => 'integer',
        ];
    }

    public function url(): string
    {
        if ($this->storage_provider === 'cloudinary') {
            return is_string($this->secure_url) && str_starts_with($this->secure_url, 'https://')
                ? $this->secure_url
                : '';
        }

        if (! is_string($this->path) || $this->path === '') {
            return '';
        }

        $segments = array_map(rawurlencode(...), explode('/', $this->path));

        return '/storage/'.implode('/', $segments);
    }

    public function storedImage(): StoredProductImage
    {
        return new StoredProductImage(
            (string) $this->storage_provider,
            $this->path,
            $this->cloudinary_public_id,
            $this->secure_url,
            $this->width,
            $this->height,
            $this->bytes,
            $this->format,
        );
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
