<?php

namespace App\Models;

use Database\Factories\CartItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OverflowException;
use UnexpectedValueException;

class CartItem extends Model
{
    /** @use HasFactory<CartItemFactory> */
    use HasFactory;

    protected $fillable = ['quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function subtotalVnd(int $unitPriceVnd): int
    {
        if ($unitPriceVnd < 0 || $this->quantity < 1) {
            throw new UnexpectedValueException('Cart item money inputs must be non-negative and quantity must be positive.');
        }

        if ($unitPriceVnd !== 0 && $this->quantity > intdiv(PHP_INT_MAX, $unitPriceVnd)) {
            throw new OverflowException('Cart item subtotal exceeds the supported integer range.');
        }

        return $unitPriceVnd * $this->quantity;
    }
}
