<?php

namespace App\Actions;

use App\Actions\Concerns\HandlesCart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AddCartItem
{
    use HandlesCart;

    public function __construct(private readonly CalculateAvailableStock $availability) {}

    public function handle(User $user, Product $product, int $quantity): CartItem
    {
        $this->assertCustomer($user);

        if ($quantity < 1) {
            throw ValidationException::withMessages(['quantity' => 'Số lượng phải lớn hơn 0.']);
        }

        return DB::transaction(function () use ($user, $product, $quantity): CartItem {
            $lockedProduct = $this->lockEligibleProduct($product->id);
            $cartItem = CartItem::query()
                ->where('user_id', $user->id)
                ->where('product_id', $lockedProduct->id)
                ->lockForUpdate()
                ->first();

            $nextQuantity = $this->addQuantities($cartItem?->quantity ?? 0, $quantity);
            $available = $this->availability->forProduct($lockedProduct);

            if ($nextQuantity > $available) {
                throw ValidationException::withMessages([
                    'quantity' => "Chỉ còn {$available} sản phẩm có thể thêm vào giỏ.",
                ]);
            }

            $this->assertSupportedSubtotal($lockedProduct, $nextQuantity);

            $cartItem ??= new CartItem;
            $cartItem->forceFill([
                'user_id' => $user->id,
                'product_id' => $lockedProduct->id,
                'quantity' => $nextQuantity,
            ])->save();

            return $cartItem;
        }, 3);
    }
}
