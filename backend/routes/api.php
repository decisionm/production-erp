<?php

use App\Modules\Core\Http\Controllers\AuthController;
use App\Modules\Core\Http\Controllers\UserController;
use App\Modules\Inventory\Http\Controllers\ItemController;
use App\Modules\Inventory\Http\Controllers\StockBalanceController;
use App\Modules\Inventory\Http\Controllers\StockMovementController;
use App\Modules\Inventory\Http\Controllers\WarehouseController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (versioned)
|--------------------------------------------------------------------------
|
| Every route here is a real product surface — it may be called by the
| bundled React SPA (session auth via Sanctum) or by other future clients
| (token auth via Sanctum personal access tokens). Never assume the SPA
| is the only caller: validate and shape responses accordingly.
|
*/

Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);

        Route::apiResource('users', UserController::class)->only(['index', 'store']);

        Route::prefix('inventory')->group(function () {
            Route::apiResource('items', ItemController::class)->only(['index', 'store', 'update']);
            Route::apiResource('warehouses', WarehouseController::class)->only(['index', 'store', 'update']);

            Route::get('stock-balances', [StockBalanceController::class, 'index']);

            Route::get('stock-movements', [StockMovementController::class, 'index']);
            Route::post('stock-movements/receipts', [StockMovementController::class, 'receipt']);
            Route::post('stock-movements/issues', [StockMovementController::class, 'issue']);
            Route::post('stock-movements/transfers', [StockMovementController::class, 'transfer']);
        });
    });
});
