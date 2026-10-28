<?php

declare(strict_types=1);

use App\Domains\Books\Http\Controllers\VoucherController;
use App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers\ComplianceController;
use App\Domains\Invoice\Http\Controllers\ContractController;
use App\Domains\Invoice\Http\Controllers\InsuranceSplitController;
use App\Domains\Invoice\Http\Controllers\InvoiceAdjustmentController;
use App\Domains\Invoice\Http\Controllers\InvoiceController;
use App\Domains\Invoice\Http\Controllers\InvoiceReceiptController;
use App\Domains\Invoice\Http\Controllers\ScheduleController;
use App\Domains\Invoice\Http\Controllers\SummaryInvoiceController;
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
|   scope:schedule.read      read invoice schedules
|   scope:schedule.manage    make and change them (implies schedule.read)
|   license.quota            consumes the licence's invoice allowance
|   sandbox.filing           issues or files: an organization's sandbox API
|                            key is refused where its country files live
|
| A licence's key, or one of the organization's own API keys made in the
| portal (Integrations), which acts under its licence with the scopes chosen
| for it. Both authenticate here.
|
*/

Route::middleware(['license', 'rate.api'])->prefix('v1')->group(function () {

    /* Invoices ------------------------------------------------------------ */

    Route::middleware(['scope:invoice.read'])->group(function () {
        Route::get('/invoices', [InvoiceController::class, 'index']);
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
        // The issued invoice as /api/invoices serves it: a PDF/A-3 with its
        // XML inside, and for a receipt printer an image or ESC/POS.
        Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'pdf']);
        Route::get('/invoices/{invoice}/receipt-image', [InvoiceReceiptController::class, 'image']);
        Route::get('/invoices/{invoice}/escpos', [InvoiceReceiptController::class, 'escPos']);
    });

    // license.feature:documents - the licence must include the kind of
    // document being written: standard or simplified, credit or debit note.
    Route::middleware(['scope:invoice.submit', 'license.feature:documents'])->group(function () {
        Route::post('/invoices', [InvoiceController::class, 'store']);
        Route::put('/invoices/{invoice}', [InvoiceController::class, 'update']);
    });

    Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])
        ->middleware(['scope:invoice.cancel']);

    // Delivery notes, and a customer's month drafted as one summary tax
    // invoice: a draft like any other, issued and counted when it is.
    Route::get('/delivery-notes', [SummaryInvoiceController::class, 'notes'])->middleware(['scope:invoice.read']);
    Route::middleware(['scope:invoice.submit', 'license.feature:documents'])->group(function () {
        Route::post('/delivery-notes', [SummaryInvoiceController::class, 'storeNote']);
        Route::delete('/delivery-notes/{id}', [SummaryInvoiceController::class, 'removeNote'])->whereUuid('id');
        Route::post('/invoices/summary', [SummaryInvoiceController::class, 'store'])->middleware('license.feature:standard_invoices');
    });

    // Vouchers: sold with no invoice and no VAT, used to pay an issued
    // invoice as its payment.
    Route::middleware(['scope:invoice.read'])->where(['code' => '[A-Za-z0-9-]{1,40}'])->group(function () {
        Route::get('/vouchers', [VoucherController::class, 'index']);
        Route::get('/vouchers/{code}', [VoucherController::class, 'show']);
    });
    Route::middleware(['scope:invoice.submit'])->where(['code' => '[A-Za-z0-9-]{1,40}'])->group(function () {
        Route::post('/vouchers', [VoucherController::class, 'store']);
        Route::post('/vouchers/{code}/redeem', [VoucherController::class, 'redeem']);
    });

    // Contracts billed in stages with retention: each stage and each
    // release of retention a draft tax invoice.
    Route::middleware(['scope:invoice.read'])->whereUuid('id')->group(function () {
        Route::get('/contracts', [ContractController::class, 'index']);
        Route::get('/contracts/{id}', [ContractController::class, 'show']);
    });
    Route::middleware(['scope:invoice.submit', 'license.feature:documents', 'license.feature:standard_invoices'])->whereUuid(['id', 'stage'])->group(function () {
        Route::post('/contracts', [ContractController::class, 'store']);
        Route::post('/contracts/{id}/stages/{stage}/invoice', [ContractController::class, 'invoiceStage']);
        Route::post('/contracts/{id}/release', [ContractController::class, 'release']);
    });

    // A sale billed in two shares: a simplified invoice to the patient and a
    // tax invoice to the insurer, so the licence must include both kinds.
    Route::get('/invoices/splits/{split}', [InsuranceSplitController::class, 'show'])
        ->middleware(['scope:invoice.read']);
    Route::post('/invoices/splits', [InsuranceSplitController::class, 'store'])
        ->middleware(['scope:invoice.submit', 'license.feature:simplified_invoices', 'license.feature:standard_invoices']);
    // The insurer's decision: a credit note on its invoice and, for care it
    // does not cover, an invoice to the patient - two documents filed, each
    // counted, so the allowance must have room for both.
    Route::post('/invoices/splits/{split}/settle', [InsuranceSplitController::class, 'settle'])
        ->middleware(['scope:invoice.submit', 'license.feature:credit_notes', 'idempotent', 'license.quota:2']);
    // A return, an exchange, a replacement or a correction on an issued
    // invoice: a note and a new invoice at most, each counted.
    Route::post('/invoices/{invoice}/adjustments', [InvoiceAdjustmentController::class, 'store'])
        ->middleware(['scope:invoice.submit', 'license.feature:credit_notes', 'idempotent', 'license.quota:2']);

    /* Compliance ----------------------------------------------------------- */

    // Generating and filing act on an invoice already stored, so the licence
    // must include that invoice's kind - license.feature:documents reads it.
    // Validating changes nothing and stays open.
    Route::middleware(['scope:invoice.submit'])->group(function () {
        Route::post('/compliance/generate/{invoiceId}', [ComplianceController::class, 'generate'])
            ->middleware(['license.feature:documents', 'sandbox.filing']);
        Route::post('/compliance/validate/{invoiceId}', [ComplianceController::class, 'validate']);
    });

    // Submitting consumes the licence's invoice allowance, so quota is checked
    // before the document is signed and sent.
    Route::post('/compliance/submit/{invoiceId}', [ComplianceController::class, 'submit'])
        ->middleware(['scope:invoice.submit', 'license.feature:documents', 'sandbox.filing', 'license.quota']);

    Route::get('/compliance/status/{invoiceId}', [ComplianceController::class, 'status'])
        ->middleware(['scope:compliance.status']);

    /* Pipeline — create, generate and submit in one atomic call ------------ */

    Route::post('/pipeline/submit', [PipelineController::class, 'submit'])
        ->middleware(['scope:invoice.submit', 'license.feature:documents', 'sandbox.filing', 'license.quota']);

    // Many invoices, each its own submission. Each invoice's document is
    // licensed as it is processed, not on the route: the batch has no single
    // document to read, and one invoice the licence does not cover is that
    // invoice's refusal, not the batch's. Batching itself is every
    // customer's unless a platform admin switches it off for one
    // (Feature::BatchSubmission); no plan restricts it.
    Route::post('/pipeline/batch', [PipelineController::class, 'batch'])
        ->middleware(['scope:invoice.submit', 'sandbox.filing', 'license.quota']);

    // A sale an insurer pays for, its invoice to the recipient drafted,
    // issued and filed in one call: one document.
    Route::post('/pipeline/split', [PipelineController::class, 'split'])
        ->middleware(['scope:invoice.submit', 'license.feature:simplified_invoices', 'license.feature:standard_invoices', 'license.quota']);

    Route::get('/pipeline/status/{invoiceId}', [PipelineController::class, 'status'])
        ->middleware(['scope:compliance.status']);

    /* Schedules -------------------------------------------------------------- */
    // Recurring invoices and instalment plans for the customer's own buyers,
    // as /api/schedules has them. Each run is checked by the same rules as
    // an invoice sent here; what it issues is the customer's, as theirs.
    Route::middleware(['scope:schedule.read'])->whereUuid('schedule')->group(function () {
        Route::get('/schedules', [ScheduleController::class, 'index']);
        Route::get('/schedules/{schedule}', [ScheduleController::class, 'show']);
    });

    Route::middleware(['scope:schedule.manage'])->whereUuid(['schedule', 'run'])->group(function () {
        Route::post('/schedules', [ScheduleController::class, 'store'])->middleware('idempotent');
        Route::post('/schedules/{schedule}/pause', [ScheduleController::class, 'pause']);
        Route::post('/schedules/{schedule}/resume', [ScheduleController::class, 'resume']);
        Route::post('/schedules/{schedule}/cancel', [ScheduleController::class, 'cancel']);
        Route::post('/schedules/{schedule}/skip', [ScheduleController::class, 'skip']);
        Route::put('/schedules/{schedule}/prices', [ScheduleController::class, 'prices']);
        Route::post('/schedules/{schedule}/runs/{run}/paid', [ScheduleController::class, 'paid']);
        Route::post('/schedules/{schedule}/runs/{run}/rerun', [ScheduleController::class, 'rerun'])->middleware('idempotent');
    });

    /* Webhooks -------------------------------------------------------------- */

    Route::middleware(['scope:webhook.manage', 'license.feature:webhook_notifications'])->group(function () {
        Route::get('/webhooks', [WebhookController::class, 'index']);
        // Before /webhooks/{webhook}, which would take "events" for an id.
        Route::get('/webhooks/events', [WebhookController::class, 'events']);
        Route::get('/webhooks/{webhook}', [WebhookController::class, 'show']);
        // What an integration needs to run its endpoint without a person's
        // token: a test delivery, a new secret, and what was delivered.
        Route::post('/webhooks/{webhook}/test', [WebhookController::class, 'test']);
        Route::post('/webhooks/{webhook}/rotate-secret', [WebhookController::class, 'rotateSecret'])->middleware('idempotent');
        Route::get('/webhooks/{webhook}/logs', [WebhookController::class, 'logs']);
        Route::post('/webhooks', [WebhookController::class, 'store'])->middleware('idempotent');
        Route::put('/webhooks/{webhook}', [WebhookController::class, 'update']);
        Route::delete('/webhooks/{webhook}', [WebhookController::class, 'destroy']);
    });

    /* Dashboard — any valid licence ------------------------------------------ */

    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/dashboard/health', [DashboardController::class, 'health']);
    Route::get('/dashboard/usage', [DashboardController::class, 'usage']);
});
