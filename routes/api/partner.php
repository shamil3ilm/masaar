<?php

declare(strict_types=1);

use App\Domains\Compliance\Fatoora\Http\Controllers\ComplianceController;
use App\Domains\Compliance\Fatoora\Http\Controllers\VarianceController;
use App\Domains\Invoice\Http\Controllers\InvoiceController;
use App\Domains\Pipeline\Http\Controllers\PipelineController;
use App\Domains\Platform\Http\Controllers\DashboardController;
use App\Domains\Webhook\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Partner API (v1) — an ERP acting for its customer
|--------------------------------------------------------------------------
|
| Authenticated by licence key and secret in headers, never the query string.
| ValidateLicense checks the credential, its expiry and suspension, records
| usage, and establishes the tenant through TenantResolver.
|
| This is the versioned public contract other systems build against, so its
| shape changes only with a version. Each route additionally declares the
| scope it needs, because a licence may hold some and not others:
|
|   scope:invoice.read       read invoices
|   scope:invoice.submit     create, update, generate and submit
|   scope:invoice.cancel     delete
|   scope:compliance.status  read submission status and variances
|   scope:webhook.manage     manage webhook subscriptions
|   license.quota            consumes the licence's invoice allowance
|
*/

Route::middleware(['license', 'rate.api'])->prefix('v1')->group(function () {

    /* Invoices ------------------------------------------------------------ */

    Route::middleware(['scope:invoice.read'])->group(function () {
        Route::get('/invoices', [InvoiceController::class, 'index']);
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
    });

    Route::middleware(['scope:invoice.submit'])->group(function () {
        Route::post('/invoices', [InvoiceController::class, 'store']);
        Route::put('/invoices/{invoice}', [InvoiceController::class, 'update']);
    });

    Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])
        ->middleware(['scope:invoice.cancel']);

    /* Compliance ----------------------------------------------------------- */

    Route::middleware(['scope:invoice.submit'])->group(function () {
        Route::post('/compliance/generate/{invoiceId}', [ComplianceController::class, 'generate']);
        Route::post('/compliance/validate/{invoiceId}', [ComplianceController::class, 'validate']);
    });

    // Submitting consumes the licence's invoice allowance, so quota is checked
    // before the document is signed and sent.
    Route::post('/compliance/submit/{invoiceId}', [ComplianceController::class, 'submit'])
        ->middleware(['scope:invoice.submit', 'license.quota']);

    Route::get('/compliance/status/{invoiceId}', [ComplianceController::class, 'status'])
        ->middleware(['scope:compliance.status']);

    /* Pipeline — create, generate and submit in one atomic call ------------ */

    Route::post('/pipeline/submit', [PipelineController::class, 'submit'])
        ->middleware(['scope:invoice.submit', 'license.quota']);

    Route::get('/pipeline/status/{invoiceId}', [PipelineController::class, 'status'])
        ->middleware(['scope:compliance.status']);

    /* Webhooks -------------------------------------------------------------- */

    Route::middleware(['scope:webhook.manage'])->group(function () {
        Route::get('/webhooks', [WebhookController::class, 'index']);
        Route::get('/webhooks/{webhook}', [WebhookController::class, 'show']);
        Route::post('/webhooks', [WebhookController::class, 'store']);
        Route::put('/webhooks/{webhook}', [WebhookController::class, 'update']);
        Route::delete('/webhooks/{webhook}', [WebhookController::class, 'destroy']);
    });

    /* Variance tracking ------------------------------------------------------ */

    Route::middleware(['scope:compliance.status'])->group(function () {
        Route::get('/variances', [VarianceController::class, 'index']);
        Route::get('/variances/statistics', [VarianceController::class, 'statistics']);
        Route::get('/variances/{id}', [VarianceController::class, 'show']);
        Route::post('/variances/{id}/report', [VarianceController::class, 'markReported']);
        Route::post('/variances/{id}/resolve', [VarianceController::class, 'resolve']);
    });

    /* Dashboard — any valid licence ------------------------------------------ */

    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/dashboard/health', [DashboardController::class, 'health']);
    Route::get('/dashboard/usage', [DashboardController::class, 'usage']);
});
