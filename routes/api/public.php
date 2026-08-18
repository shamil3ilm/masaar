<?php

declare(strict_types=1);

use App\Domains\Auth\Http\Controllers\AuthController;
use App\Domains\Licensing\Services\PlatformLicenseService;
use App\Domains\Platform\Http\Controllers\MetricsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public API
|--------------------------------------------------------------------------
|
| Reachable without a credential. Everything here is deliberately public, and
| RouteAuthPostureTest fails the build if an unguarded route appears anywhere
| else, so this file is the whole of the unauthenticated surface.
|
*/

Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'version' => '1.0.0',
    'timestamp' => now()->toISOString(),
]));

// Lets a partner check its own licence before attempting real calls.
Route::get('/license/status', function () {
    $result = app(PlatformLicenseService::class)->validate();

    return response()->json([
        'valid' => $result['valid'],
        'type' => $result['type'],
        'partner' => $result['partner'],
        'expires_at' => $result['expires_at'],
        'days_remaining' => $result['days_remaining'],
        'message' => $result['message'],
        'features' => $result['features'] ?? [],
    ]);
});

// Prometheus metrics. Discloses application and PHP version, APP_ENV and
// business telemetry, so `metrics` closes it by default and opens it only via
// METRICS_ALLOWED_IPS or METRICS_TOKEN. See config/metrics.php.
Route::get('/metrics', [MetricsController::class, 'index'])
    ->middleware(['metrics', 'throttle:60,1']);

// Credential exchange. Everything past this point needs what these return.
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});
