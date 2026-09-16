<?php

use App\Http\Controllers\Auth\RegisteredCustomerController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisteredCustomerController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredCustomerController::class, 'store']);
});
