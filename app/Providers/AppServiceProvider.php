<?php

namespace App\Providers;

use App\Contracts\VnPayRefundTransport;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\CartItem;
use App\Services\VnPayRefundGateway;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(VnPayRefundTransport::class, VnPayRefundGateway::class);
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
