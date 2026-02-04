<?php

use App\Http\Controllers\Api\V1\Inventory\CategoryController;
use App\Http\Controllers\Api\V1\Inventory\ProductController;
use App\Http\Controllers\Api\V1\Inventory\StockAdjustmentController;
use App\Http\Controllers\Api\V1\Inventory\StockController;
use App\Http\Controllers\Api\V1\Inventory\StockTransferController;
use App\Http\Controllers\Api\V1\Inventory\WarehouseController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Inventory API Routes
|--------------------------------------------------------------------------
|
| All routes are prefixed with /api/v1/inventory
|
*/

Route::middleware(['auth:api'])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Categories
    |--------------------------------------------------------------------------
    */
    Route::prefix('categories')->group(function () {
        Route::get('/', [CategoryController::class, 'index'])->name('inventory.categories.index');
        Route::post('/', [CategoryController::class, 'store'])->name('inventory.categories.store');
        Route::get('/{category}', [CategoryController::class, 'show'])->name('inventory.categories.show');
        Route::put('/{category}', [CategoryController::class, 'update'])->name('inventory.categories.update');
        Route::delete('/{category}', [CategoryController::class, 'destroy'])->name('inventory.categories.destroy');
        Route::post('/{category}/move', [CategoryController::class, 'move'])->name('inventory.categories.move');
    });

    /*
    |--------------------------------------------------------------------------
    | Products
    |--------------------------------------------------------------------------
    */
    Route::prefix('products')->group(function () {
        Route::get('/', [ProductController::class, 'index'])->name('inventory.products.index');
        Route::post('/', [ProductController::class, 'store'])->name('inventory.products.store');
        Route::get('/reorder-list', [ProductController::class, 'reorderList'])->name('inventory.products.reorder-list');
        Route::post('/bulk-update-prices', [ProductController::class, 'bulkUpdatePrices'])->name('inventory.products.bulk-update-prices');
        Route::get('/{product}', [ProductController::class, 'show'])->name('inventory.products.show');
        Route::put('/{product}', [ProductController::class, 'update'])->name('inventory.products.update');
        Route::delete('/{product}', [ProductController::class, 'destroy'])->name('inventory.products.destroy');
        Route::get('/{product}/stock', [ProductController::class, 'stock'])->name('inventory.products.stock');
        Route::post('/{product}/clone', [ProductController::class, 'clone'])->name('inventory.products.clone');
    });

    /*
    |--------------------------------------------------------------------------
    | Warehouses
    |--------------------------------------------------------------------------
    */
    Route::prefix('warehouses')->group(function () {
        Route::get('/', [WarehouseController::class, 'index'])->name('inventory.warehouses.index');
        Route::post('/', [WarehouseController::class, 'store'])->name('inventory.warehouses.store');
        Route::get('/{warehouse}', [WarehouseController::class, 'show'])->name('inventory.warehouses.show');
        Route::put('/{warehouse}', [WarehouseController::class, 'update'])->name('inventory.warehouses.update');
        Route::delete('/{warehouse}', [WarehouseController::class, 'destroy'])->name('inventory.warehouses.destroy');
        Route::get('/{warehouse}/stock-valuation', [WarehouseController::class, 'stockValuation'])->name('inventory.warehouses.stock-valuation');
        Route::get('/{warehouse}/low-stock', [WarehouseController::class, 'lowStock'])->name('inventory.warehouses.low-stock');
        Route::post('/{warehouse}/set-default', [WarehouseController::class, 'setDefault'])->name('inventory.warehouses.set-default');
    });

    /*
    |--------------------------------------------------------------------------
    | Stock
    |--------------------------------------------------------------------------
    */
    Route::prefix('stock')->group(function () {
        Route::get('/levels', [StockController::class, 'levels'])->name('inventory.stock.levels');
        Route::get('/movements', [StockController::class, 'movements'])->name('inventory.stock.movements');
        Route::get('/valuation', [StockController::class, 'valuation'])->name('inventory.stock.valuation');
        Route::get('/low-stock', [StockController::class, 'lowStock'])->name('inventory.stock.low-stock');
        Route::post('/check-availability', [StockController::class, 'checkAvailability'])->name('inventory.stock.check-availability');
        Route::post('/reserve', [StockController::class, 'reserve'])->name('inventory.stock.reserve');
        Route::post('/release', [StockController::class, 'release'])->name('inventory.stock.release');
    });

    /*
    |--------------------------------------------------------------------------
    | Stock Adjustments
    |--------------------------------------------------------------------------
    */
    Route::prefix('stock-adjustments')->group(function () {
        Route::get('/', [StockAdjustmentController::class, 'index'])->name('inventory.adjustments.index');
        Route::post('/', [StockAdjustmentController::class, 'store'])->name('inventory.adjustments.store');
        Route::post('/quick-adjust', [StockAdjustmentController::class, 'quickAdjust'])->name('inventory.adjustments.quick-adjust');
        Route::get('/{stockAdjustment}', [StockAdjustmentController::class, 'show'])->name('inventory.adjustments.show');
        Route::put('/{stockAdjustment}', [StockAdjustmentController::class, 'update'])->name('inventory.adjustments.update');
        Route::post('/{stockAdjustment}/post', [StockAdjustmentController::class, 'post'])->name('inventory.adjustments.post');
        Route::post('/{stockAdjustment}/cancel', [StockAdjustmentController::class, 'cancel'])->name('inventory.adjustments.cancel');
        Route::get('/{stockAdjustment}/summary', [StockAdjustmentController::class, 'summary'])->name('inventory.adjustments.summary');
    });

    /*
    |--------------------------------------------------------------------------
    | Stock Transfers
    |--------------------------------------------------------------------------
    */
    Route::prefix('stock-transfers')->group(function () {
        Route::get('/', [StockTransferController::class, 'index'])->name('inventory.transfers.index');
        Route::post('/', [StockTransferController::class, 'store'])->name('inventory.transfers.store');
        Route::get('/pending', [StockTransferController::class, 'pending'])->name('inventory.transfers.pending');
        Route::get('/overdue', [StockTransferController::class, 'overdue'])->name('inventory.transfers.overdue');
        Route::get('/{stockTransfer}', [StockTransferController::class, 'show'])->name('inventory.transfers.show');
        Route::put('/{stockTransfer}', [StockTransferController::class, 'update'])->name('inventory.transfers.update');
        Route::post('/{stockTransfer}/ship', [StockTransferController::class, 'ship'])->name('inventory.transfers.ship');
        Route::post('/{stockTransfer}/receive', [StockTransferController::class, 'receive'])->name('inventory.transfers.receive');
        Route::post('/{stockTransfer}/cancel', [StockTransferController::class, 'cancel'])->name('inventory.transfers.cancel');
        Route::get('/{stockTransfer}/summary', [StockTransferController::class, 'summary'])->name('inventory.transfers.summary');
    });
});
