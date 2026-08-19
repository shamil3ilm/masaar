<?php

declare(strict_types=1);

use App\Domains\Auth\Http\Controllers\AuthController;
use App\Domains\Compliance\Fatoora\Http\Controllers\BranchOnboardingController;
use App\Domains\Compliance\Fatoora\Http\Controllers\ComplianceController;
use App\Domains\Compliance\Fatoora\Http\Controllers\OnboardingController;
use App\Domains\Compliance\FTA\Http\Controllers\FtaController;
use App\Domains\Invoice\Http\Controllers\InvoiceController;
use App\Domains\Organization\Http\Controllers\BranchController;
use App\Domains\Organization\Http\Controllers\ComplianceProfileController;
use App\Domains\Organization\Http\Controllers\OrganizationController;
use App\Domains\Platform\Http\Controllers\DashboardController;
use App\Domains\Webhook\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant API — a signed-in person acting for one organization
|--------------------------------------------------------------------------
|
| Authenticated by JWT. JwtGuard establishes the tenant through TenantResolver,
| which is what BelongsToTenant reads, so every query in this surface is scoped
| to the caller's organization without the controllers asking for it.
|
| The guard is declared once, here, for the whole file.
|
*/

Route::middleware(['jwt.auth', 'rate.api'])->group(function () {

    /* Session ------------------------------------------------------------ */

    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/refresh', [AuthController::class, 'refresh']);
        Route::get('/me', [AuthController::class, 'me']);
    });

    /* Invoices ----------------------------------------------------------- */

    Route::apiResource('invoices', InvoiceController::class);

    /* Compliance — Saudi Arabia (ZATCA Fatoora, Phase 2) ------------------ */

    Route::prefix('compliance/sa')->group(function () {
        Route::post('/generate/{invoiceId}', [ComplianceController::class, 'generate']);
        Route::post('/validate/{invoiceId}', [ComplianceController::class, 'validate']);
        Route::post('/submit/{invoiceId}', [ComplianceController::class, 'submit']);
        Route::get('/status/{submissionId}', [ComplianceController::class, 'status']);
    });

    Route::prefix('compliance/onboarding')->group(function () {
        Route::get('/status', [OnboardingController::class, 'status']);
        Route::post('/ccsid', [OnboardingController::class, 'requestCcsid']);
        Route::post('/compliance-check', [OnboardingController::class, 'runComplianceCheck']);
        Route::post('/pcsid', [OnboardingController::class, 'requestPcsid']);
    });

    /* Compliance — United Arab Emirates (FTA, Peppol PINT AE) ------------- */

    Route::prefix('compliance/ae')->group(function () {
        Route::get('/submissions', [FtaController::class, 'index']);
        Route::post('/submit/{invoiceId}', [FtaController::class, 'submit']);
        Route::get('/status/{submissionId}', [FtaController::class, 'status']);
        Route::post('/retry/{submissionId}', [FtaController::class, 'retry']);
    });

    /* Organizations and branches ------------------------------------------ */

    Route::apiResource('organizations', OrganizationController::class)->except(['destroy']);
    Route::post('/organizations/{id}/switch', [OrganizationController::class, 'switch']);

    Route::prefix('organizations/{organization}')->group(function () {
        Route::get('/compliance-profiles', [ComplianceProfileController::class, 'index']);
        Route::post('/compliance-profiles', [ComplianceProfileController::class, 'store']);
        Route::delete('/compliance-profiles/{profile}', [ComplianceProfileController::class, 'destroy']);
    });

    // Branches are ZATCA EGS units: each signs with its own certificate.
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

        Route::post('/{branch}/onboarding/ccsid', [BranchOnboardingController::class, 'requestCcsid']);
        Route::post('/{branch}/onboarding/compliance-check', [BranchOnboardingController::class, 'runComplianceCheck']);
        Route::post('/{branch}/onboarding/pcsid', [BranchOnboardingController::class, 'requestPcsid']);
        Route::post('/{branch}/onboarding/reset', [BranchOnboardingController::class, 'resetOnboarding']);
    });

    /* Integration management ---------------------------------------------- */

    // Declared before the resource so /webhooks/events is not captured by
    // /webhooks/{webhook}.
    Route::get('/webhooks/events', [WebhookController::class, 'events']);
    Route::apiResource('webhooks', WebhookController::class);
    Route::post('/webhooks/{id}/test', [WebhookController::class, 'test']);
    Route::post('/webhooks/{id}/rotate-secret', [WebhookController::class, 'rotateSecret']);
    Route::get('/webhooks/{id}/logs', [WebhookController::class, 'logs']);

    /* Dashboard ------------------------------------------------------------ */

    Route::prefix('dashboard')->group(function () {
        Route::get('/', [DashboardController::class, 'index']);
        Route::get('/invoices', [DashboardController::class, 'invoices']);
        Route::get('/submissions', [DashboardController::class, 'submissions']);
        Route::get('/health', [DashboardController::class, 'health']);
        Route::get('/usage', [DashboardController::class, 'usage']);
        Route::get('/activity', [DashboardController::class, 'activity']);
    });
});
