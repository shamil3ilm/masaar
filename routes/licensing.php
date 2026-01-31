<?php

declare(strict_types=1);

use App\Domains\Licensing\Http\Controllers\LicenseController;
use App\Domains\Licensing\Http\Controllers\LicenseUsageController;
use App\Domains\Licensing\Http\Middleware\ValidateLicense;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Licensing API Routes
|--------------------------------------------------------------------------
|
| Routes for license management and usage tracking.
|
*/

/*
|--------------------------------------------------------------------------
| Admin Routes (Protected by admin authentication)
|--------------------------------------------------------------------------
*/
Route::prefix('api/admin/licenses')
    ->middleware(['auth:sanctum', 'admin'])
    ->group(function () {
        // Statistics endpoint (before {id} routes)
        Route::get('statistics', [LicenseController::class, 'statistics']);

        // Cleanup endpoint
        Route::post('cleanup', [LicenseController::class, 'cleanup']);

        // CRUD operations
        Route::get('/', [LicenseController::class, 'index']);
        Route::post('/', [LicenseController::class, 'store']);
        Route::get('{id}', [LicenseController::class, 'show']);

        // License actions
        Route::post('{id}/activate', [LicenseController::class, 'activate']);
        Route::post('{id}/suspend', [LicenseController::class, 'suspend']);
        Route::post('{id}/reactivate', [LicenseController::class, 'reactivate']);
        Route::post('{id}/revoke', [LicenseController::class, 'revoke']);
        Route::post('{id}/extend', [LicenseController::class, 'extend']);
        Route::post('{id}/upgrade', [LicenseController::class, 'upgrade']);
        Route::post('{id}/regenerate-secret', [LicenseController::class, 'regenerateSecret']);

        // License configuration
        Route::patch('{id}/limits', [LicenseController::class, 'updateLimits']);
        Route::patch('{id}/features', [LicenseController::class, 'updateFeatures']);

        // License data
        Route::get('{id}/usage', [LicenseController::class, 'usage']);
        Route::get('{id}/audit', [LicenseController::class, 'audit']);
    });

/*
|--------------------------------------------------------------------------
| Self-Service Routes (Protected by API key)
|--------------------------------------------------------------------------
*/
Route::prefix('api/license')
    ->middleware([ValidateLicense::class])
    ->group(function () {
        Route::get('/', [LicenseUsageController::class, 'info']);
        Route::get('usage', [LicenseUsageController::class, 'usage']);
        Route::get('quotas', [LicenseUsageController::class, 'quotas']);
        Route::get('health', [LicenseUsageController::class, 'health']);
        Route::get('features/{feature}', [LicenseUsageController::class, 'checkFeature']);
    });
