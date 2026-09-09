<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\FoodItemController;
use App\Http\Controllers\Api\V1\InventoryController;
use Illuminate\Support\Facades\Route;

Route::pattern('item', '[0-9]+');

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:auth')->name('auth.register');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:auth')->name('auth.login');
    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::apiResource('items', FoodItemController::class)->except('update');
        Route::put('items/{item}', [FoodItemController::class, 'update'])->whereNumber('item')->name('items.update');
        Route::post('inventory-days', [InventoryController::class, 'open'])->name('days.open');
        Route::post('inventory-days/{date}/movements', [InventoryController::class, 'movement'])->name('days.movements');
        Route::post('inventory-days/{date}/close', [InventoryController::class, 'close'])->name('days.close');
        Route::get('inventory/history', [InventoryController::class, 'history'])->name('inventory.history');
        Route::get('inventory/daily-summary', [InventoryController::class, 'summary'])->name('inventory.summary');
    });
});
