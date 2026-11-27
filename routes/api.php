<?php

use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\TransactionController;
use Illuminate\Support\Facades\Route;

Route::prefix('products')->controller(ProductController::class)->group(function () {
    Route::get('/', 'index');
    Route::post('/', 'store');
    Route::get('/{productId}', 'show')->whereUuid('productId');
    Route::put('/{productId}', 'update')->whereUuid('productId');
    Route::delete('/{productId}', 'destroy')->whereUuid('productId');
});

Route::post('/transactions', [TransactionController::class, 'store']);
