<?php

use App\Http\Controllers\Api\V1\Manufacturing\BomController;
use App\Http\Controllers\Api\V1\Manufacturing\WorkOrderController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Manufacturing API Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum'])->group(function () {
    /*
    |--------------------------------------------------------------------------
    | BOM Templates
    |--------------------------------------------------------------------------
    */
    Route::prefix('bom-templates')->group(function () {
        Route::get('/', [BomController::class, 'index']);
        Route::post('/', [BomController::class, 'store']);
        Route::get('/for-product', [BomController::class, 'forProduct']);
        Route::get('/{bom}', [BomController::class, 'show']);
        Route::put('/{bom}', [BomController::class, 'update']);
        Route::delete('/{bom}', [BomController::class, 'destroy']);

        // Actions
        Route::post('/{bom}/activate', [BomController::class, 'activate']);
        Route::post('/{bom}/deactivate', [BomController::class, 'deactivate']);
        Route::post('/{bom}/duplicate', [BomController::class, 'duplicate']);

        // Analysis
        Route::get('/{bom}/cost-breakdown', [BomController::class, 'costBreakdown']);
        Route::get('/{bom}/check-availability', [BomController::class, 'checkAvailability']);
    });

    /*
    |--------------------------------------------------------------------------
    | Work Orders
    |--------------------------------------------------------------------------
    */
    Route::prefix('work-orders')->group(function () {
        Route::get('/', [WorkOrderController::class, 'index']);
        Route::post('/', [WorkOrderController::class, 'store']);
        Route::get('/statistics', [WorkOrderController::class, 'statistics']);
        Route::get('/production-schedule', [WorkOrderController::class, 'schedule']);
        Route::get('/{workOrder}', [WorkOrderController::class, 'show']);
        Route::put('/{workOrder}', [WorkOrderController::class, 'update']);
        Route::delete('/{workOrder}', [WorkOrderController::class, 'destroy']);

        // Status transitions
        Route::post('/{workOrder}/release', [WorkOrderController::class, 'release']);
        Route::post('/{workOrder}/schedule', [WorkOrderController::class, 'schedule']);
        Route::post('/{workOrder}/start', [WorkOrderController::class, 'start']);
        Route::post('/{workOrder}/complete', [WorkOrderController::class, 'complete']);
        Route::post('/{workOrder}/cancel', [WorkOrderController::class, 'cancel']);

        // Material management
        Route::post('/{workOrder}/issue-materials', [WorkOrderController::class, 'issueMaterials']);
        Route::post('/{workOrder}/return-materials', [WorkOrderController::class, 'returnMaterials']);
        Route::post('/{workOrder}/consume-materials', [WorkOrderController::class, 'consumeMaterials']);

        // Production
        Route::post('/{workOrder}/record-production', [WorkOrderController::class, 'recordProduction']);

        // Operations
        Route::post('/{workOrder}/operations/{operation}/start', [WorkOrderController::class, 'startOperation']);
        Route::post('/{workOrder}/operations/{operation}/complete', [WorkOrderController::class, 'completeOperation']);
    });
});
