<?php

declare(strict_types=1);

use App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers\ComplianceController;
use App\Domains\Invoice\Http\Controllers\InsuranceSplitController;
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
|   scope:compliance.status  read submission status
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

    // license.feature:documents - the licence must include the kind of
    // document being written: standard or simplified, credit or debit note.
    Route::middleware(['scope:invoice.submit', 'license.feature:documents'])->group(function () {
        Route::post('/invoices', [InvoiceController::class, 'store']);
        Route::put('/invoices/{invoice}', [InvoiceController::class, 'update']);
    });

    Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])
        ->middleware(['scope:invoice.cancel']);

    // A sale billed in two shares: a simplified invoice to the patient and a
    // tax invoice to the insurer, so the licence must include both kinds.
    Route::get('/invoices/splits/{split}', [InsuranceSplitController::class, 'show'])
        ->middleware(['scope:invoice.read']);
    Route::post('/invoices/splits', [InsuranceSplitController::class, 'store'])
        ->middleware(['scope:invoice.submit', 'license.feature:simplified_invoices', 'license.feature:standard_invoices']);

    /* Compliance ----------------------------------------------------------- */

    // Generating and filing act on an invoice already stored, so the licence
    // must include that invoice's kind - license.feature:documents reads it.
    // Validating changes nothing and stays open.
    Route::middleware(['scope:invoice.submit'])->group(function () {
        Route::post('/compliance/generate/{invoiceId}', [ComplianceController::class, 'generate'])
            ->middleware('license.feature:documents');
        Route::post('/compliance/validate/{invoiceId}', [ComplianceController::class, 'validate']);
    });

    // Submitting consumes the licence's invoice allowance, so quota is checked
    // before the document is signed and sent.
    Route::post('/compliance/submit/{invoiceId}', [ComplianceController::class, 'submit'])
        ->middleware(['scope:invoice.submit', 'license.feature:documents', 'license.quota']);

    Route::get('/compliance/status/{invoiceId}', [ComplianceController::class, 'status'])
        ->middleware(['scope:compliance.status']);

    /* Pipeline — create, generate and submit in one atomic call ------------ */

    Route::post('/pipeline/submit', [PipelineController::class, 'submit'])
        ->middleware(['scope:invoice.submit', 'license.feature:documents', 'license.quota']);

    // Many invoices, each its own submission. Each invoice's document is
    // licensed as it is processed, not on the route: the batch has no single
    // document to read, and one invoice the licence does not cover is that
    // invoice's refusal, not the batch's. Batching itself is every
    // customer's unless a platform admin switches it off for one
    // (Feature::BatchSubmission); no plan restricts it.
    Route::post('/pipeline/batch', [PipelineController::class, 'batch'])
        ->middleware(['scope:invoice.submit', 'license.quota']);

    Route::get('/pipeline/status/{invoiceId}', [PipelineController::class, 'status'])
        ->middleware(['scope:compliance.status']);

    /* Webhooks -------------------------------------------------------------- */

    Route::middleware(['scope:webhook.manage', 'license.feature:webhook_notifications'])->group(function () {
        Route::get('/webhooks', [WebhookController::class, 'index']);
        Route::get('/webhooks/{webhook}', [WebhookController::class, 'show']);
        Route::post('/webhooks', [WebhookController::class, 'store']);
        Route::put('/webhooks/{webhook}', [WebhookController::class, 'update']);
        Route::delete('/webhooks/{webhook}', [WebhookController::class, 'destroy']);
    });

    /* Dashboard — any valid licence ------------------------------------------ */

    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/dashboard/health', [DashboardController::class, 'health']);
    Route::get('/dashboard/usage', [DashboardController::class, 'usage']);
});
