<?php

namespace App\Actions;

use App\Actions\Concerns\HandlesCart;
use App\Models\CartItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class RemoveCartItem
{
    use HandlesCart;

    public function handle(User $user, CartItem $cartItem): void
    {
        $this->assertCustomer($user);

        DB::transaction(function () use ($user, $cartItem): void {
            $lockedItem = CartItem::query()->lockForUpdate()->findOrFail($cartItem->id);

            if ($lockedItem->user_id !== $user->id) {
                throw new AuthorizationException;
            }

            $lockedItem->delete();
        }, 3);
    }
}
