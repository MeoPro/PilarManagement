<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ColorController;
use App\Http\Controllers\GuestController;
use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('orders.index');
});

// ==========================
// ORDERS
// ==========================

Route::get('/orders/check-id', [OrderController::class, 'checkOrderId'])
    ->name('orders.check-id');
Route::post('/guests', [GuestController::class, 'store'])->name('guests.store');
Route::get('/guests', [GuestController::class, 'index'])->name('guests.index');

Route::resource('orders', OrderController::class);

// ==========================
// CATEGORIES
// ==========================

Route::resource('categories', CategoryController::class);

Route::post('/colors', [ColorController::class, 'store'])->name('colors.store');
