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
| Admin Routes (Masaar-internal license administration)
|--------------------------------------------------------------------------
|
| These issue, revoke and regenerate the secrets of customer licences, so
| they are gated the same way as the rest of the cross-tenant surface:
| 'jwt.auth' to establish identity, 'platform.admin' for the Masaar-internal
| privilege.
|
| Previously 'auth:sanctum' — a guard this application does not define, with
| Sanctum not installed — followed by 'admin', which only checks the JWT's
| per-organization role. The undefined guard meant every one of these routes
| raised "Auth guard [sanctum] is not defined" rather than authorizing
| anything.
|
*/
Route::prefix('api/admin/licenses')
    ->middleware(['jwt.auth', 'platform.admin', 'admin.can:console.view', 'admin.audit'])
    ->group(function () {
        // Statistics endpoint (before {id} routes)
        Route::get('statistics', [LicenseController::class, 'statistics']);

        // Cleanup endpoint
        Route::post('cleanup', [LicenseController::class, 'cleanup'])->middleware('admin.can:licences.manage');

        // CRUD operations
        Route::get('/', [LicenseController::class, 'index']);
        Route::post('/', [LicenseController::class, 'store'])->middleware('admin.can:licences.manage');
        Route::get('{id}', [LicenseController::class, 'show']);

        // License actions
        Route::post('{id}/activate', [LicenseController::class, 'activate'])->middleware('admin.can:licences.manage');
        Route::post('{id}/suspend', [LicenseController::class, 'suspend'])->middleware('admin.can:licences.manage');
        Route::post('{id}/reactivate', [LicenseController::class, 'reactivate'])->middleware('admin.can:licences.manage');
        Route::post('{id}/revoke', [LicenseController::class, 'revoke'])->middleware('admin.can:licences.manage');
        Route::post('{id}/extend', [LicenseController::class, 'extend'])->middleware('admin.can:licences.manage');
        Route::post('{id}/upgrade', [LicenseController::class, 'upgrade'])->middleware('admin.can:licences.manage');
        Route::post('{id}/regenerate-secret', [LicenseController::class, 'regenerateSecret'])->middleware('admin.can:licences.manage');

        // License configuration
        Route::patch('{id}/limits', [LicenseController::class, 'updateLimits'])->middleware('admin.can:licences.manage');
        Route::patch('{id}/features', [LicenseController::class, 'updateFeatures'])->middleware('admin.can:licences.manage');

        // License data
        Route::get('{id}/usage', [LicenseController::class, 'usage']);
        Route::get('{id}/audit', [LicenseController::class, 'audit'])->middleware('admin.can:audit.view');
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
