<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group.
|
*/

// API Version 1
Route::prefix('v1')->group(function () {
    // Auth routes (public)
    require __DIR__.'/api/v1/auth.php';

    // Protected routes
    Route::middleware(['auth:api', 'validate.jwt', 'check.organization'])->group(function () {
        // Core module routes (always enabled)
        require __DIR__.'/api/v1/core.php';

        // Accounting module routes (with module check)
        Route::middleware(['check.module:accounting'])->group(function () {
            require __DIR__.'/api/v1/accounting.php';
        });

        // Inventory module routes (with module check)
        Route::prefix('inventory')->middleware(['check.module:inventory'])->group(function () {
            require __DIR__.'/api/v1/inventory.php';
        });

        // Sales module routes (with module check)
        Route::prefix('sales')->middleware(['check.module:sales'])->group(function () {
            require __DIR__.'/api/v1/sales.php';
        });

        // Purchase module routes (with module check)
        Route::prefix('purchase')->middleware(['check.module:purchase'])->group(function () {
            require __DIR__.'/api/v1/purchase.php';
        });

        // HR module routes (with module check)
        Route::prefix('hr')->middleware(['check.module:hr'])->group(function () {
            require __DIR__.'/api/v1/hr.php';
        });

        // CRM module routes (with module check)
        Route::prefix('crm')->middleware(['check.module:crm'])->group(function () {
            require __DIR__.'/api/v1/crm.php';
        });

        // Manufacturing module routes (with module check)
        Route::prefix('manufacturing')->middleware(['check.module:manufacturing'])->group(function () {
            require __DIR__.'/api/v1/manufacturing.php';
        });

        // Reports & Dashboard routes (core, always available)
        require __DIR__.'/api/v1/reports.php';
    });
});
