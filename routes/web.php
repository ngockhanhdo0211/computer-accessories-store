<?php

use App\Enums\UserRole;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CouponController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductImageController;
use App\Http\Controllers\Admin\RefundController;
use App\Http\Controllers\Admin\ShippingRateController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredCustomerController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\CustomerOrderController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\ManagedOrderCancellationRequestController;
use App\Http\Controllers\ManagedOrderController;
use App\Http\Controllers\OrderCancellationRequestController;
use App\Http\Controllers\VnPayIpnController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::get('/products', [CatalogController::class, 'index'])->name('products.index');
Route::get('/products/{product}', [CatalogController::class, 'show'])->name('products.show');
Route::get('/checkout/vnpay/return', [CheckoutController::class, 'vnpayReturn'])
    ->name('checkout.vnpay.return');
Route::get('/checkout/vnpay/ipn', VnPayIpnController::class)
    ->name('checkout.vnpay.ipn');

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
        Route::post('/vnpay', [CheckoutController::class, 'initiateVnPay'])->name('vnpay.initiate');
    });

    Route::middleware('role:customer')->prefix('orders')->name('orders.')->group(function () {
        Route::get('/', [CustomerOrderController::class, 'index'])->name('index');
        Route::get('/{orderCode}', [CustomerOrderController::class, 'show'])
            ->where('orderCode', '[A-Za-z0-9][A-Za-z0-9-]{0,39}')
            ->name('show');
        Route::post('/{orderCode}/cancellation-request', [OrderCancellationRequestController::class, 'store'])
            ->where('orderCode', '[A-Za-z0-9][A-Za-z0-9-]{0,39}')
            ->name('cancellation-request.store');
    });

    Route::get('/dashboard', function () {
        $role = UserRole::tryFrom((string) request()->user()->getRawOriginal('role'));
        abort_unless($role !== null, 403);

        return redirect()->route($role->dashboardRouteName());
    })->name('dashboard');

    Route::get('/customer/dashboard', fn () => view('dashboard'))
        ->middleware('role:customer')->name('customer.dashboard');
    Route::get('/employee/dashboard', fn () => view('dashboard'))
        ->middleware('role:employee')->name('employee.dashboard');
    Route::middleware('role:employee')->prefix('employee')->name('employee.')->group(function () {
        Route::get('/order-cancellation-requests', [ManagedOrderCancellationRequestController::class, 'index'])->name('order-cancellation-requests.index');
        Route::get('/order-cancellation-requests/{cancellationRequest}', [ManagedOrderCancellationRequestController::class, 'show'])->name('order-cancellation-requests.show');
        Route::patch('/order-cancellation-requests/{cancellationRequest}/approve', [ManagedOrderCancellationRequestController::class, 'approve'])->name('order-cancellation-requests.approve');
        Route::patch('/order-cancellation-requests/{cancellationRequest}/reject', [ManagedOrderCancellationRequestController::class, 'reject'])->name('order-cancellation-requests.reject');
        Route::get('/orders', [ManagedOrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/{orderCode}', [ManagedOrderController::class, 'show'])
            ->where('orderCode', '[A-Za-z0-9][A-Za-z0-9-]{0,39}')
            ->name('orders.show');
        Route::patch('/orders/{orderCode}/status', [ManagedOrderController::class, 'transition'])
            ->where('orderCode', '[A-Za-z0-9][A-Za-z0-9-]{0,39}')
            ->name('orders.transition');
        Route::patch('/orders/{orderCode}/cancel', [ManagedOrderController::class, 'cancel'])
            ->where('orderCode', '[A-Za-z0-9][A-Za-z0-9-]{0,39}')
            ->name('orders.cancel');
        Route::patch('/orders/{orderCode}/deliver', [ManagedOrderController::class, 'deliver'])
            ->where('orderCode', '[A-Za-z0-9][A-Za-z0-9-]{0,39}')
            ->name('orders.deliver');
    });
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
        Route::get('/order-cancellation-requests', [ManagedOrderCancellationRequestController::class, 'index'])->name('order-cancellation-requests.index');
        Route::get('/order-cancellation-requests/{cancellationRequest}', [ManagedOrderCancellationRequestController::class, 'show'])->name('order-cancellation-requests.show');
        Route::patch('/order-cancellation-requests/{cancellationRequest}/approve', [ManagedOrderCancellationRequestController::class, 'approve'])->name('order-cancellation-requests.approve');
        Route::patch('/order-cancellation-requests/{cancellationRequest}/reject', [ManagedOrderCancellationRequestController::class, 'reject'])->name('order-cancellation-requests.reject');
        Route::get('/refunds', [RefundController::class, 'index'])->name('refunds.index');
        Route::get('/refunds/{refund}', [RefundController::class, 'show'])->name('refunds.show');
        Route::post('/refunds/{refund}/submit', [RefundController::class, 'submit'])->name('refunds.submit');
        Route::patch('/refunds/{refund}/mark-ambiguous', [RefundController::class, 'markAmbiguous'])->name('refunds.mark-ambiguous');
        Route::patch('/refunds/{refund}/reconcile', [RefundController::class, 'reconcile'])->name('refunds.reconcile');
        Route::get('/orders', [ManagedOrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/{orderCode}', [ManagedOrderController::class, 'show'])
            ->where('orderCode', '[A-Za-z0-9][A-Za-z0-9-]{0,39}')
            ->name('orders.show');
        Route::patch('/orders/{orderCode}/status', [ManagedOrderController::class, 'transition'])
            ->where('orderCode', '[A-Za-z0-9][A-Za-z0-9-]{0,39}')
            ->name('orders.transition');
        Route::patch('/orders/{orderCode}/cancel', [ManagedOrderController::class, 'cancel'])
            ->where('orderCode', '[A-Za-z0-9][A-Za-z0-9-]{0,39}')
            ->name('orders.cancel');
        Route::patch('/orders/{orderCode}/deliver', [ManagedOrderController::class, 'deliver'])
            ->where('orderCode', '[A-Za-z0-9][A-Za-z0-9-]{0,39}')
            ->name('orders.deliver');
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
