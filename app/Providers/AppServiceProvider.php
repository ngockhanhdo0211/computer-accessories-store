<?php

namespace App\Providers;

use App\Contracts\ProductImageStorageResolver;
use App\Contracts\VnPayRefundTransport;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\CartItem;
use App\Services\ProductImageStorageManager;
use App\Services\VnPayRefundGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(VnPayRefundTransport::class, VnPayRefundGateway::class);
        $this->app->singleton(ProductImageStorageResolver::class, ProductImageStorageManager::class);
    }

    public function boot(): void
    {
        RateLimiter::for('support-customer', fn (Request $request) => Limit::perMinute(10)
            ->by('support-customer:'.$request->user()->id.'|'.$request->ip()));
        RateLimiter::for('support-staff', fn (Request $request) => Limit::perMinute(30)
            ->by('support-staff:'.$request->user()->id.'|'.$request->ip()));

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
