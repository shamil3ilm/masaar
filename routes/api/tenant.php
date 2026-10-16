<?php

declare(strict_types=1);

use App\Domains\Auth\Http\Controllers\AuthController;
use App\Domains\Compliance\Fatoora\Http\Controllers\BranchOnboardingController;
use App\Domains\Compliance\Fatoora\Http\Controllers\ComplianceController;
use App\Domains\Compliance\Fatoora\Http\Controllers\OnboardingController;
use App\Domains\Invoice\Http\Controllers\InvoiceController;
use App\Domains\Invoice\Http\Controllers\NumberingController;
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

    // Deleting a document the authority may already hold is not a member's
    // to do; everything else about invoicing is.
    // org.writer: a viewer reads invoices and changes none.
    Route::apiResource('invoices', InvoiceController::class)->only(['index', 'show']);
    // The issued invoice as a PDF/A-3 with its XML inside.
    Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'pdf']);
    Route::apiResource('invoices', InvoiceController::class)->only(['store', 'update'])->middleware('org.writer');
    Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])
        ->middleware('org.admin');

    // How invoices sent without a number are numbered when issued.
    Route::get('/settings/numbering', [NumberingController::class, 'index']);
    Route::put('/settings/numbering/{documentType}', [NumberingController::class, 'update'])
        ->middleware('org.admin');

    /* Compliance — Saudi Arabia (ZATCA Fatoora, Phase 2) ------------------ */

    Route::prefix('compliance/sa')->middleware('org.writer')->group(function () {
        Route::post('/generate/{invoiceId}', [ComplianceController::class, 'generate']);
        Route::post('/validate/{invoiceId}', [ComplianceController::class, 'validate']);
        Route::post('/submit/{invoiceId}', [ComplianceController::class, 'submit']);
        Route::get('/status/{submissionId}', [ComplianceController::class, 'status']);
    });

    Route::prefix('compliance/onboarding')->group(function () {
        Route::get('/status', [OnboardingController::class, 'status']);

        // Obtaining a CSID is how the organization gets the keys every invoice
        // is signed with, so it belongs to whoever runs it.
        Route::middleware(['org.admin', 'filing.cleared'])->group(function () {
            Route::post('/ccsid', [OnboardingController::class, 'requestCcsid']);
            Route::post('/compliance-check', [OnboardingController::class, 'runComplianceCheck']);
            Route::post('/pcsid', [OnboardingController::class, 'requestPcsid']);
        });
    });

    /* Organizations and branches ------------------------------------------ */

    Route::prefix('organizations/branches')->middleware('org.writer')->group(function () {
        Route::get('/', [BranchController::class, 'index']);
        Route::post('/', [BranchController::class, 'store']);
        Route::get('/{branch}', [BranchController::class, 'show']);
        Route::put('/{branch}', [BranchController::class, 'update']);
        Route::get('/{branch}/onboarding-status', [BranchController::class, 'onboardingStatus']);

        // A branch's lifecycle and its credentials reach past the person
        // changing them: suspending one stops its invoicing, and resetting an
        // onboarding discards a CSID that cannot be re-issued without another
        // OTP from the portal.
        Route::middleware('org.admin')->group(function () {
            Route::delete('/{branch}', [BranchController::class, 'destroy']);
            Route::post('/{branch}/set-default', [BranchController::class, 'setDefault']);
            Route::post('/{branch}/suspend', [BranchController::class, 'suspend']);
            Route::post('/{branch}/reactivate', [BranchController::class, 'reactivate']);

            Route::middleware('filing.cleared')->group(function () {
                Route::post('/{branch}/onboarding/ccsid', [BranchOnboardingController::class, 'requestCcsid']);
                Route::post('/{branch}/onboarding/compliance-check', [BranchOnboardingController::class, 'runComplianceCheck']);
                Route::post('/{branch}/onboarding/pcsid', [BranchOnboardingController::class, 'requestPcsid']);
            });
            Route::post('/{branch}/onboarding/reset', [BranchOnboardingController::class, 'resetOnboarding']);
        });
    });

    // Branches first, and the order is the whole point.
    //
    // Laravel matches in declaration order. Declared after the
    // organizations resource, GET organizations/branches was answered by
    // GET organizations/{organization} with the word 'branches' as the id,
    // so listing a taxpayer's branches returned a 404 that read like an
    // empty account rather than a route nothing could reach. POST to the
    // same path worked, because no POST organizations/{organization}
    // exists to catch it - which is the kind of half-working that a smoke
    // test walks straight past. RouteShadowTest now asserts it.
    Route::apiResource('organizations', OrganizationController::class)->except(['destroy', 'update']);
    // Both verbs, because that is what apiResource's update answers and
    // declaring it separately must not quietly retire PATCH.
    Route::match(['put', 'patch'], '/organizations/{organization}', [OrganizationController::class, 'update'])
        ->middleware('org.admin');
    Route::post('/organizations/{id}/switch', [OrganizationController::class, 'switch']);

    Route::prefix('organizations/{organization}')->group(function () {
        Route::get('/compliance-profiles', [ComplianceProfileController::class, 'index']);
        Route::post('/compliance-profiles', [ComplianceProfileController::class, 'store'])
            ->middleware('org.admin');
        Route::delete('/compliance-profiles/{profile}', [ComplianceProfileController::class, 'destroy'])
            ->middleware('org.admin');
    });

    /* Integration management ---------------------------------------------- */

    // Declared before the resource so /webhooks/events is not captured by
    // /webhooks/{webhook}.
    Route::get('/webhooks/events', [WebhookController::class, 'events']);
    Route::apiResource('webhooks', WebhookController::class)->only(['index', 'show']);

    // Where the organization's invoice events go is an administrator's call:
    // a member who could create or repoint a webhook received every clearance
    // and rejection at an address of their choosing, or switched the ERP's off.
    Route::middleware('org.admin')->group(function () {
        Route::post('/webhooks', [WebhookController::class, 'store'])->name('webhooks.store');
        Route::match(['put', 'patch'], '/webhooks/{webhook}', [WebhookController::class, 'update'])->name('webhooks.update');
        Route::delete('/webhooks/{webhook}', [WebhookController::class, 'destroy']);
    });
    Route::post('/webhooks/{id}/test', [WebhookController::class, 'test'])->middleware('org.writer');
    Route::post('/webhooks/{id}/rotate-secret', [WebhookController::class, 'rotateSecret'])
        ->middleware('org.admin');
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
