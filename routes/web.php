<?php

use App\Enums\UserRole;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CouponController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductImageController;
use App\Http\Controllers\Admin\ShippingRateController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredCustomerController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\OrderReceiptController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::get('/products', [CatalogController::class, 'index'])->name('products.index');
Route::get('/products/{product}', [CatalogController::class, 'show'])->name('products.show');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
    Route::get('/register', [RegisteredCustomerController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredCustomerController::class, 'store']);
});

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::middleware('role:customer')->prefix('cart')->name('cart.')->group(function () {
        Route::get('/', [CartController::class, 'index'])->name('index');
        Route::post('/items/{product}', [CartController::class, 'store'])->name('items.store');
        Route::patch('/items/{cartItem}', [CartController::class, 'update'])->name('items.update');
        Route::delete('/items/{cartItem}', [CartController::class, 'destroy'])->name('items.destroy');
    });

    Route::middleware('role:customer')->prefix('checkout')->name('checkout.')->group(function () {
        Route::get('/', [CheckoutController::class, 'show'])->name('show');
        Route::post('/quote', [CheckoutController::class, 'quote'])->name('quote');
        Route::post('/cod', [CheckoutController::class, 'storeCod'])->name('cod.store');
    });

    Route::get('/orders/{orderCode}', OrderReceiptController::class)
        ->middleware('role:customer')
        ->name('orders.show');

    Route::get('/dashboard', function () {
        $role = UserRole::tryFrom((string) request()->user()->getRawOriginal('role'));
        abort_unless($role !== null, 403);

        return redirect()->route($role->dashboardRouteName());
    })->name('dashboard');

    Route::get('/customer/dashboard', fn () => view('dashboard'))
        ->middleware('role:customer')->name('customer.dashboard');
    Route::get('/employee/dashboard', fn () => view('dashboard'))
        ->middleware('role:employee')->name('employee.dashboard');
    Route::get('/admin/dashboard', AdminDashboardController::class)
        ->middleware('role:admin')->name('admin.dashboard');

    Route::middleware('role:employee,admin')->prefix('inventory')->name('inventory.')->group(function () {
        Route::get('/', [InventoryController::class, 'index'])->name('index');
        Route::get('/adjustments', [InventoryController::class, 'adjustments'])->name('adjustments.index');
        Route::get('/{product}/history', [InventoryController::class, 'history'])->name('history');
        Route::get('/{product}/import', [InventoryController::class, 'importForm'])->name('import.form');
        Route::post('/{product}/import', [InventoryController::class, 'import'])->name('import');
        Route::get('/{product}/damaged', [InventoryController::class, 'damagedForm'])->name('damaged.form');
        Route::post('/{product}/damaged', [InventoryController::class, 'damaged'])->name('damaged');
        Route::get('/{product}/adjustments/create', [InventoryController::class, 'adjustmentForm'])->name('adjustments.create');
        Route::post('/{product}/adjustments', [InventoryController::class, 'requestAdjustment'])->name('adjustments.store');

        Route::middleware('role:admin')->group(function () {
            Route::get('/{product}/adjustments/direct', [InventoryController::class, 'directAdjustmentForm'])->name('adjustments.direct.form');
            Route::post('/{product}/adjustments/direct', [InventoryController::class, 'directAdjustment'])->name('adjustments.direct');
            Route::patch('/adjustments/{adjustment}/approve', [InventoryController::class, 'approve'])->name('adjustments.approve');
            Route::patch('/adjustments/{adjustment}/reject', [InventoryController::class, 'reject'])->name('adjustments.reject');
        });
    });

    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::resource('categories', CategoryController::class)->except('show');
        Route::resource('brands', BrandController::class)->except('show');
        Route::resource('coupons', CouponController::class)->except('show');
        Route::resource('products', ProductController::class)->except('show');
        Route::resource('shipping-rates', ShippingRateController::class)->only(['index', 'edit', 'update']);
        Route::post('products/{product}/images', [ProductImageController::class, 'store'])
            ->name('products.images.store');
        Route::patch('products/{product}/images', [ProductImageController::class, 'update'])
            ->name('products.images.update');
        Route::delete('products/{product}/images/{image}', [ProductImageController::class, 'destroy'])
            ->name('products.images.destroy');
    });
});
