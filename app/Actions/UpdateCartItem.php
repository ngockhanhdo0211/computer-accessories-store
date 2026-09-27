<?php

namespace App\Actions;

use App\Actions\Concerns\HandlesCart;
use App\Models\CartItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateCartItem
{
    use HandlesCart;

    public function __construct(private readonly CalculateAvailableStock $availability) {}

    public function handle(User $user, CartItem $cartItem, int $quantity): CartItem
    {
        $this->assertCustomer($user);

        if ($quantity < 1) {
            throw ValidationException::withMessages(['quantity' => 'Số lượng phải lớn hơn 0.']);
        }

        return DB::transaction(function () use ($user, $cartItem, $quantity): CartItem {
            $lockedProduct = $this->lockEligibleProduct($cartItem->product_id);
            $lockedItem = CartItem::query()->lockForUpdate()->findOrFail($cartItem->id);

            if ($lockedItem->user_id !== $user->id || $lockedItem->product_id !== $lockedProduct->id) {
                throw new AuthorizationException;
            }

            $available = $this->availability->forProduct($lockedProduct);

            if ($quantity > $available) {
                throw ValidationException::withMessages([
                    'quantity' => "Chỉ còn {$available} sản phẩm có thể giữ trong giỏ.",
                ]);
            }

            $this->assertSupportedSubtotal($lockedProduct, $quantity);

            $lockedItem->quantity = $quantity;
            $lockedItem->save();

            return $lockedItem;
        }, 3);
    }
}
