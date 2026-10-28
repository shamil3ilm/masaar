<?php

declare(strict_types=1);

use App\Domains\Auth\Http\Controllers\AuthController;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraEnvironment;
use App\Domains\Compliance\Networks\Peppol\Http\Controllers\InboundWebhookController;
use App\Domains\Compliance\Networks\Peppol\Http\Controllers\StatusWebhookController;
use App\Domains\Licensing\Services\PlatformLicenseService;
use App\Domains\Platform\Http\Controllers\MetricsController;
use App\Domains\Platform\Http\Controllers\ReadinessController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public API
|--------------------------------------------------------------------------
|
| Reachable without a credential. Everything here is deliberately public, and
| ApiRouteAuthPostureTest fails the build if an API route asks for no
| credential without being listed there as well, so this file is the whole of
| the unauthenticated API surface. RouteAuthPostureTest does the same for the
| routes served as pages.
|
*/

// rate.api on all but the provider callbacks at the end, as tenant.php, partner.php and platform.php
// declare it for theirs. This was the one audience without it, which left
// /auth/login and /auth/register with no limit at all - an unlimited number
// of password attempts against a known address, and unlimited account
// creation. Unauthenticated traffic draws on the tighter 'anonymous' budget;
// see RateLimitApi::handle().
Route::middleware('rate.api')->group(function () {

    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'version' => '1.0.0',
        'timestamp' => now()->toISOString(),
        // Which ZATCA environment this deployment files with, so monitoring
        // can alert on a live server left on simulation or the sandbox.
        'zatca_environment' => (string) config('fatoora.environment'),
        // Each organization may have an environment of its own, so the
        // default alone can hide a production organization on a server that
        // defaults to the sandbox: every environment in use. Null when the
        // database cannot say, reported, because liveness answers while PHP
        // does, database or not.
        'zatca_environments' => rescue(fn (): array => FatooraEnvironment::inUse(), null),
    ]));

    // Liveness above, for the container: it answers while PHP does, because
    // restarting the app does not bring a database back. Readiness here, for
    // the load balancer: 503 when this instance could not serve a request.
    Route::get('/health/ready', [ReadinessController::class, 'show']);

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
        Route::post('/email/resend', [AuthController::class, 'resendVerification'])->middleware('throttle:5,1');
    });

});

// The Peppol service provider's callbacks: a document's status, and documents
// other suppliers sent to our organizations. The provider holds no credential
// of ours, so the HMAC signature on each request is the credential, checked
// first by the controller. Outside rate.api: its anonymous budget of twenty a
// minute would throttle a provider reporting a busy day. Limited on their own
// instead; a refused callback is retried by the provider, and missed statuses
// are polled for (peppol:poll-status).
Route::prefix('peppol/webhooks')->middleware('throttle:300,1')->group(function () {
    Route::post('/status', [StatusWebhookController::class, 'store']);
    Route::post('/inbound', [InboundWebhookController::class, 'store']);
});
