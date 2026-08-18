<?php

declare(strict_types=1);

use App\Domains\Platform\Http\Controllers\AdminDashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Platform API — Masaar staff, across every tenant
|--------------------------------------------------------------------------
|
| These routes read platform-wide data spanning all organizations, so the gate
| is `platform.admin`, which checks users.is_platform_admin.
|
| It is deliberately NOT the per-organization `admin` role. Every customer's
| own org-admin carries that, and using it here would hand one tenant a view of
| all the others. This matches the gate on the Blade console, and
| AdminApiAccessTest asserts it on every route under /admin.
|
*/

Route::middleware(['jwt.auth', 'platform.admin', 'rate.api'])
    ->prefix('admin/dashboard')
    ->group(function () {

        Route::get('/', [AdminDashboardController::class, 'index']);
        Route::get('/health', [AdminDashboardController::class, 'health']);
        Route::get('/issues', [AdminDashboardController::class, 'issues']);
        Route::get('/logs', [AdminDashboardController::class, 'logs']);
        Route::get('/top-organizations', [AdminDashboardController::class, 'topOrganizations']);
        Route::get('/error-rates', [AdminDashboardController::class, 'errorRates']);
        Route::get('/variances', [AdminDashboardController::class, 'environmentVariances']);
        Route::get('/hash-chain-health', [AdminDashboardController::class, 'hashChainHealth']);
        Route::post('/run-health-check', [AdminDashboardController::class, 'runHealthCheck']);

        /* ZATCA connectivity ------------------------------------------------ */

        Route::get('/connectivity', [AdminDashboardController::class, 'connectivity']);
        Route::post('/connectivity/refresh', [AdminDashboardController::class, 'refreshConnectivity']);
        Route::post('/circuit-breaker/reset', [AdminDashboardController::class, 'resetCircuitBreaker']);

        /* Offline queue ----------------------------------------------------- */

        Route::get('/offline-queue', [AdminDashboardController::class, 'offlineQueue']);
        Route::get('/offline-queue/{organizationId}', [AdminDashboardController::class, 'offlineQueueByOrg']);
        Route::post('/offline-queue/process', [AdminDashboardController::class, 'processOfflineQueue']);
        Route::post('/offline-queue/{queueId}/retry', [AdminDashboardController::class, 'retryQueueItem']);
    });
