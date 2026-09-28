<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('orders.index');
});


// ==========================
// ORDERS
// ==========================

Route::resource('orders', OrderController::class);


// ==========================
// CATEGORIES
// ==========================

Route::resource('categories', CategoryController::class);