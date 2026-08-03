<?php

use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\ApiKeyController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\BranchOnboardingController;
use App\Http\Controllers\Api\ComplianceController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\MetricsController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\OrganizationController;
use App\Domains\Pipeline\Http\Controllers\PipelineController;
use App\Http\Controllers\Api\VarianceController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Masaar API endpoints for authentication, invoices, and Fatoora (KSA) compliance.
|
| Authentication Methods:
| 1. JWT Token: POST /api/auth/login -> Authorization: Bearer {token}
| 2. API Key: POST /api/api-keys -> X-API-Key: {key}
|
*/

// Health check (public)
Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'version' => '1.0.0',
    'timestamp' => now()->toISOString(),
]));

// Platform license status (public - for partners to check their license)
Route::get('/license/status', function () {
    $licenseService = app(\App\Domains\Licensing\Services\PlatformLicenseService::class);
    $result = $licenseService->validate();

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

// Prometheus metrics. Discloses application/PHP version, APP_ENV and business
// telemetry, so access is closed by default and opened via METRICS_ALLOWED_IPS
// or METRICS_TOKEN. See config/metrics.php.
Route::get('/metrics', [MetricsController::class, 'index'])
    ->middleware(['metrics', 'throttle:60,1']);

/*
|--------------------------------------------------------------------------
| Public Auth Routes
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

/*
|--------------------------------------------------------------------------
| JWT Protected Routes (User Authentication)
|--------------------------------------------------------------------------
*/
Route::middleware(['jwt.auth', 'rate.api'])->group(function () {

    // Auth management
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/refresh', [AuthController::class, 'refresh']);
        Route::get('/me', [AuthController::class, 'me']);
    });

    // Invoices (full CRUD)
    Route::apiResource('invoices', InvoiceController::class);

    // Saudi Arabia — Fatoora (KSA) Compliance (Phase 2 e-invoicing)
    Route::prefix('compliance/sa')->group(function () {
        Route::post('/generate/{invoiceId}', [ComplianceController::class, 'generate']);
        Route::post('/validate/{invoiceId}', [ComplianceController::class, 'validate']);
        Route::post('/submit/{invoiceId}', [ComplianceController::class, 'submit']);
        Route::get('/status/{submissionId}', [ComplianceController::class, 'status']);
    });

    // Deprecated: /compliance/zatca/ → /compliance/sa/ (remove in v2.0)
    Route::prefix('compliance/zatca')->group(function () {
        Route::any('/{path?}', function () {
            return response()->json([
                'message' => 'This endpoint has moved. Use /api/compliance/sa/ instead.',
                'docs' => 'https://docs.masaar.sa/migration/v1-to-v2',
            ], 301);
        })->where('path', '.*');
    });

    // UAE FTA compliance endpoints (Peppol PINT AE)
    Route::prefix('compliance/ae')->group(function () {
        Route::post('/submit/{invoiceId}', [\App\Http\Controllers\Api\FTA\FtaController::class, 'submit']);
        Route::get('/status/{submissionId}', [\App\Http\Controllers\Api\FTA\FtaController::class, 'status']);
        Route::post('/retry/{submissionId}', [\App\Http\Controllers\Api\FTA\FtaController::class, 'retry']);
        Route::get('/submissions', [\App\Http\Controllers\Api\FTA\FtaController::class, 'index']);
    });

    // Deprecated: /compliance/uae-fta/ → /compliance/ae/ (remove in v2.0)
    Route::prefix('compliance/uae-fta')->group(function () {
        Route::any('/{path?}', function () {
            return response()->json([
                'message' => 'This endpoint has moved. Use /api/compliance/ae/ instead.',
                'docs' => 'https://docs.masaar.sa/migration/v1-to-v2',
            ], 301);
        })->where('path', '.*');
    });

    // Saudi Arabia — Fatoora Onboarding (CSID flow)
    Route::prefix('compliance/onboarding')->group(function () {
        Route::get('/status', [OnboardingController::class, 'status']);
        Route::post('/ccsid', [OnboardingController::class, 'requestCcsid']);
        Route::post('/compliance-check', [OnboardingController::class, 'runComplianceCheck']);
        Route::post('/pcsid', [OnboardingController::class, 'requestPcsid']);
    });

    // Environment Variance Tracking
    Route::prefix('compliance/variances')->group(function () {
        Route::get('/', [VarianceController::class, 'index']);
        Route::get('/statistics', [VarianceController::class, 'statistics']);
        Route::get('/{id}', [VarianceController::class, 'show']);
        Route::post('/{id}/report', [VarianceController::class, 'markReported']);
        Route::post('/{id}/resolve', [VarianceController::class, 'resolve']);
    });

    // Organizations
    Route::apiResource('organizations', OrganizationController::class)->except(['destroy']);
    Route::post('/organizations/{id}/switch', [OrganizationController::class, 'switch']);

    // Compliance Profile CRUD (per organization)
    Route::prefix('organizations/{organization}')->group(function () {
        Route::get('/compliance-profiles', [\App\Http\Controllers\Api\ComplianceProfileController::class, 'index']);
        Route::post('/compliance-profiles', [\App\Http\Controllers\Api\ComplianceProfileController::class, 'store']);
        Route::delete('/compliance-profiles/{profile}', [\App\Http\Controllers\Api\ComplianceProfileController::class, 'destroy']);
    });

    // Branches (EGS Units)
    Route::prefix('organizations/branches')->group(function () {
        Route::get('/', [BranchController::class, 'index']);
        Route::post('/', [BranchController::class, 'store']);
        Route::get('/{branch}', [BranchController::class, 'show']);
        Route::put('/{branch}', [BranchController::class, 'update']);
        Route::delete('/{branch}', [BranchController::class, 'destroy']);
        Route::post('/{branch}/set-default', [BranchController::class, 'setDefault']);
        Route::post('/{branch}/suspend', [BranchController::class, 'suspend']);
        Route::post('/{branch}/reactivate', [BranchController::class, 'reactivate']);
        Route::get('/{branch}/onboarding-status', [BranchController::class, 'onboardingStatus']);

        // Branch Fatoora Onboarding
        Route::post('/{branch}/onboarding/ccsid', [BranchOnboardingController::class, 'requestCcsid']);
        Route::post('/{branch}/onboarding/compliance-check', [BranchOnboardingController::class, 'runComplianceCheck']);
        Route::post('/{branch}/onboarding/pcsid', [BranchOnboardingController::class, 'requestPcsid']);
        Route::post('/{branch}/onboarding/reset', [BranchOnboardingController::class, 'resetOnboarding']);
    });

    // Webhooks management
    Route::get('/webhooks/events', [WebhookController::class, 'events']);
    Route::apiResource('webhooks', WebhookController::class);
    Route::post('/webhooks/{id}/test', [WebhookController::class, 'test']);
    Route::post('/webhooks/{id}/rotate-secret', [WebhookController::class, 'rotateSecret']);
    Route::get('/webhooks/{id}/logs', [WebhookController::class, 'logs']);

    // API Keys management
    Route::get('/api-keys/scopes', [ApiKeyController::class, 'scopes']);
    Route::apiResource('api-keys', ApiKeyController::class);

    // Dashboard & Statistics
    Route::prefix('dashboard')->group(function () {
        Route::get('/', [DashboardController::class, 'index']);
        Route::get('/invoices', [DashboardController::class, 'invoices']);
        Route::get('/submissions', [DashboardController::class, 'submissions']);
        Route::get('/health', [DashboardController::class, 'health']);
        Route::get('/usage', [DashboardController::class, 'usage']);
        Route::get('/activity', [DashboardController::class, 'activity']);
    });
});

/*
|--------------------------------------------------------------------------
| API Key Protected Routes (Server-to-Server Authentication)
|--------------------------------------------------------------------------
|
| These routes use API key authentication with license validation.
| Pass the key in the X-API-Key header (format: cp_live_xxx or cp_test_xxx).
|
| Middleware Stack:
| - license: Validates API key, checks expiry/suspension, records usage
| - scope:xxx: Checks if license has required scope for the operation
| - license.quota: Checks invoice quota before allowing submission
|
*/
Route::middleware(['license', 'rate.api'])->prefix('v1')->group(function () {

    // Invoices - Read operations (require invoice.read scope)
    Route::middleware(['scope:invoice.read'])->group(function () {
        Route::get('/invoices', [InvoiceController::class, 'index']);
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
    });

    // Invoices - Write operations (require invoice.submit scope for create/update)
    Route::middleware(['scope:invoice.submit'])->group(function () {
        Route::post('/invoices', [InvoiceController::class, 'store']);
        Route::put('/invoices/{invoice}', [InvoiceController::class, 'update']);
    });

    // Invoice delete (require invoice.cancel scope)
    Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])
        ->middleware(['scope:invoice.cancel']);

    // Fatoora (KSA) Compliance - Generate & Validate (require invoice.submit scope)
    Route::middleware(['scope:invoice.submit'])->group(function () {
        Route::post('/compliance/generate/{invoiceId}', [ComplianceController::class, 'generate']);
        Route::post('/compliance/validate/{invoiceId}', [ComplianceController::class, 'validate']);
    });

    // Fatoora (KSA) Compliance - Submit (requires quota check + production environment for real submissions)
    Route::post('/compliance/submit/{invoiceId}', [ComplianceController::class, 'submit'])
        ->middleware(['scope:invoice.submit', 'license.quota']);

    // Fatoora (KSA) Compliance - Status (require compliance.status scope)
    Route::get('/compliance/status/{invoiceId}', [ComplianceController::class, 'status'])
        ->middleware(['scope:compliance.status']);

    // Webhooks (require webhook.manage scope)
    Route::middleware(['scope:webhook.manage'])->group(function () {
        Route::get('/webhooks', [WebhookController::class, 'index']);
        Route::get('/webhooks/{webhook}', [WebhookController::class, 'show']);
        Route::post('/webhooks', [WebhookController::class, 'store']);
        Route::put('/webhooks/{webhook}', [WebhookController::class, 'update']);
        Route::delete('/webhooks/{webhook}', [WebhookController::class, 'destroy']);
    });

    // Environment Variance Tracking (require compliance.status scope)
    Route::middleware(['scope:compliance.status'])->group(function () {
        Route::get('/variances', [VarianceController::class, 'index']);
        Route::get('/variances/statistics', [VarianceController::class, 'statistics']);
        Route::get('/variances/{id}', [VarianceController::class, 'show']);
        Route::post('/variances/{id}/report', [VarianceController::class, 'markReported']);
        Route::post('/variances/{id}/resolve', [VarianceController::class, 'resolve']);
    });

    // Pipeline - Atomic ERP integration (create + generate + submit in one call)
    Route::post('/pipeline/submit', [PipelineController::class, 'submit'])
        ->middleware(['scope:invoice.submit', 'license.quota']);
    Route::get('/pipeline/status/{invoiceId}', [PipelineController::class, 'status'])
        ->middleware(['scope:compliance.status']);

    // Dashboard - Read (require any valid license)
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/dashboard/health', [DashboardController::class, 'health']);
    Route::get('/dashboard/usage', [DashboardController::class, 'usage']);
});

/*
|--------------------------------------------------------------------------
| Admin Routes (Platform Administration)
|--------------------------------------------------------------------------
|
| These routes require admin authentication and provide platform-wide
| statistics and system health monitoring.
|
| The 'admin' middleware verifies that the authenticated user carries
| role=admin in their JWT organization context. Any other role receives 403.
|
*/
Route::middleware(['jwt.auth', 'admin', 'rate.api'])->prefix('admin')->group(function () {

    // Admin Dashboard
    Route::prefix('dashboard')->group(function () {
        Route::get('/', [AdminDashboardController::class, 'index']);
        Route::get('/health', [AdminDashboardController::class, 'health']);
        Route::get('/top-organizations', [AdminDashboardController::class, 'topOrganizations']);
        Route::post('/run-health-check', [AdminDashboardController::class, 'runHealthCheck']);
        Route::get('/error-rates', [AdminDashboardController::class, 'errorRates']);
        Route::get('/variances', [AdminDashboardController::class, 'environmentVariances']);
        Route::get('/hash-chain-health', [AdminDashboardController::class, 'hashChainHealth']);

        // Connectivity & Status
        Route::get('/connectivity', [AdminDashboardController::class, 'connectivity']);
        Route::post('/connectivity/refresh', [AdminDashboardController::class, 'refreshConnectivity']);

        // Offline Queue Management
        Route::get('/offline-queue', [AdminDashboardController::class, 'offlineQueue']);
        Route::get('/offline-queue/{organizationId}', [AdminDashboardController::class, 'offlineQueueByOrg']);
        Route::post('/offline-queue/process', [AdminDashboardController::class, 'processOfflineQueue']);
        Route::post('/offline-queue/{queueId}/retry', [AdminDashboardController::class, 'retryQueueItem']);

        // Issues & Alerts
        Route::get('/issues', [AdminDashboardController::class, 'issues']);

        // Logs
        Route::get('/logs', [AdminDashboardController::class, 'logs']);

        // Circuit Breaker
        Route::post('/circuit-breaker/reset', [AdminDashboardController::class, 'resetCircuitBreaker']);
    });
});
