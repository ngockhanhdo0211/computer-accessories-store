<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\CartItem;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        View::composer('layouts.storefront', function ($view): void {
            $user = auth()->user();
            $isActiveCustomer = $user !== null
                && UserRole::tryFrom((string) $user->getRawOriginal('role')) === UserRole::Customer
                && UserStatus::tryFrom((string) $user->getRawOriginal('status')) === UserStatus::Active;

            $requestCount = request()->attributes->get('cartItemCount');
            $cartItemCount = $isActiveCustomer
                ? (is_int($requestCount)
                    ? $requestCount
                    : CartItem::query()->where('user_id', $user->id)->count())
                : null;

            $view->with('cartItemCount', $cartItemCount);
        });
    }
}
