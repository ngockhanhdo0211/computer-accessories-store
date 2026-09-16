<?php

use App\Enums\UserRole;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredCustomerController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
    Route::get('/register', [RegisteredCustomerController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredCustomerController::class, 'store']);
});

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/dashboard', function () {
        $role = UserRole::tryFrom((string) request()->user()->getRawOriginal('role'));
        abort_unless($role !== null, 403);

        return redirect()->route($role->dashboardRouteName());
    })->name('dashboard');

    Route::get('/customer/dashboard', fn () => view('dashboard'))
        ->middleware('role:customer')->name('customer.dashboard');
    Route::get('/employee/dashboard', fn () => view('dashboard'))
        ->middleware('role:employee')->name('employee.dashboard');
    Route::get('/admin/dashboard', fn () => view('dashboard'))
        ->middleware('role:admin')->name('admin.dashboard');
});
