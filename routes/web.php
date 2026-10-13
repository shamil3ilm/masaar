<?php

use App\Domains\Auth\Http\Controllers\SessionAuthController;
use App\Domains\Compliance\Fatoora\Http\Controllers\AdminAlertController;
use App\Domains\Invoice\Http\Controllers\PortalInvoiceController;
use App\Domains\Invoice\Http\Controllers\PortalInvoiceDraftController;
use App\Domains\Invoice\Http\Controllers\PortalInvoiceFilingController;
use App\Domains\Licensing\Http\Controllers\AdminLicenceController;
use App\Domains\Organization\Http\Controllers\CustomerPortalController;
use App\Domains\Platform\Http\Controllers\AdminController;
use App\Domains\Webhook\Http\Controllers\AdminWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Session Authentication (Blade surfaces)
|--------------------------------------------------------------------------
|
| The JSON API uses JWT and API keys, neither of which yields a session. The
| server-rendered admin console and customer portal authenticate here.
|
*/
Route::get('/login', [SessionAuthController::class, 'showLogin'])->name('login');
Route::post('/login', [SessionAuthController::class, 'login'])
    ->middleware('throttle:10,1')
    ->name('login.attempt');
Route::post('/logout', [SessionAuthController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

/*
|--------------------------------------------------------------------------
| Admin Dashboard Routes (Masaar Internal)
|--------------------------------------------------------------------------
|
| Cross-tenant console. `platform.admin` checks the platform-wide privilege,
| NOT the per-organization `admin` pivot role — a customer's org-admin must not
| reach these views.
|
*/
Route::prefix('admin')->name('admin.')
    ->middleware(['auth', 'platform.admin'])
    // Every identifier in this group names a HasUuids model, so anything that
    // is not a UUID is a mistyped URL and is a 404 at the router, before a
    // query is built from it. Two reasons beyond tidiness:
    //
    // MySQL compares a malformed id against a char column and simply finds
    // nothing, but Postgres rejects it as invalid input syntax for type uuid -
    // an unhandled driver error carrying the statement, which with APP_DEBUG
    // on is the query echoed back to whoever typed it. Staging is MySQL and
    // nothing says it has to stay that way.
    //
    // It also makes the ordering of /licences/create above /licences/{id}
    // a convenience rather than a requirement: "create" is not a UUID, so it
    // can no longer be swallowed as one if the lines are ever reordered.
    ->whereUuid(['id', 'licenceId', 'webhookId', 'logId'])
    ->group(function () {
        Route::get('/', [AdminController::class, 'dashboard'])->name('dashboard');
        Route::get('/organizations', [AdminController::class, 'organizations'])->name('organizations');
        Route::get('/organizations/{id}', [AdminController::class, 'organizationDetail'])->name('organization.detail');
        Route::get('/licences', [AdminLicenceController::class, 'index'])->name('licences.index');
        // Before the {licenceId} route below, or "create" is taken for an id
        // and the form never renders.
        Route::get('/licences/create', [AdminLicenceController::class, 'create'])->name('licences.create');
        Route::post('/licences', [AdminLicenceController::class, 'store'])->name('licences.store');
        Route::get('/licences/{licenceId}', [AdminLicenceController::class, 'show'])->name('licences.show');
        Route::post('/licences/{licenceId}/suspend', [AdminLicenceController::class, 'suspend'])->name('licences.suspend');
        Route::post('/licences/{licenceId}/reactivate', [AdminLicenceController::class, 'reactivate'])->name('licences.reactivate');
        Route::post('/licences/{licenceId}/revoke', [AdminLicenceController::class, 'revoke'])->name('licences.revoke');
        Route::post('/licences/{licenceId}/rotate', [AdminLicenceController::class, 'rotate'])->name('licences.rotate');
        Route::get('/alerts', [AdminAlertController::class, 'index'])->name('alerts');
        Route::get('/webhooks', [AdminWebhookController::class, 'index'])->name('webhooks.index');
        // {webhookId} rather than {webhook}: route model binding would resolve
        // through TenantScope, and a platform admin holds no tenant, so every
        // endpoint would be a 404. WebhookReport steps past the scope
        // deliberately instead.
        Route::get('/webhooks/{webhookId}', [AdminWebhookController::class, 'show'])->name('webhooks.show');
        // Posts the stored payload to a customer's live endpoint, so it is a
        // POST behind a confirm rather than anything a browser or a crawler can
        // follow. The log id is nested under the endpoint because the endpoint
        // is what authorizes it: AdminWebhookController refuses a log that
        // belongs to another one.
        Route::post('/webhooks/{webhookId}/replay/{logId}', [AdminWebhookController::class, 'replay'])
            ->name('webhooks.replay');
        Route::get('/queue', [AdminController::class, 'queue'])->name('queue');
        Route::get('/logs', [AdminController::class, 'logs'])->name('logs');

        // Actions
        Route::post('/queue/process', [AdminController::class, 'processQueue'])->name('queue.process');
        Route::post('/queue/{id}/retry', [AdminController::class, 'retryQueueItem'])->name('queue.retry');
    });

/*
|--------------------------------------------------------------------------
| Customer Portal Routes (Northwind, etc.)
|--------------------------------------------------------------------------
|
| Tenant-scoped dashboard for customers to monitor their ZATCA compliance.
| `portal.tenant` derives the organization from the authenticated session's
| memberships; the controllers never read a tenant identifier from input.
|
*/
Route::prefix('portal')->name('portal.')->middleware('auth')->group(function () {
    // Clears the session selection, so it sits outside `portal.tenant`, which
    // would otherwise immediately re-resolve it.
    Route::get('/switch', [CustomerPortalController::class, 'switchOrganization'])->name('switch');
});

Route::prefix('portal')->name('portal.')
    ->middleware(['auth', 'portal.tenant'])
    ->whereUuid(['invoiceId', 'userId'])
    ->group(function () {
        Route::get('/', [CustomerPortalController::class, 'dashboard'])->name('dashboard');
        // Invoices. {invoiceId} rather than {invoice}: route model binding
        // would lean on TenantScope alone, and the scope stands down in
        // console context - PortalInvoiceController names the organization in
        // the query instead, so a foreign id is unfindable rather than merely
        // unscoped.
        Route::get('/invoices', [PortalInvoiceController::class, 'index'])->name('invoices.index');
        // Before the {invoiceId} route. The group constrains that parameter to
        // a UUID so "create" could not be swallowed as one, but the reading
        // order should not depend on knowing that.
        Route::get('/invoices/create', [PortalInvoiceDraftController::class, 'create'])->name('invoices.create');
        Route::post('/invoices', [PortalInvoiceDraftController::class, 'store'])->name('invoices.store');
        Route::get('/invoices/{invoiceId}', [PortalInvoiceController::class, 'show'])->name('invoices.show');
        Route::post('/invoices/{invoiceId}/issue', [PortalInvoiceFilingController::class, 'issue'])->name('invoices.issue');
        Route::get('/invoices/{invoiceId}/print', [PortalInvoiceFilingController::class, 'print'])->name('invoices.print');

        Route::get('/submissions', [CustomerPortalController::class, 'submissions'])->name('submissions');
        Route::get('/certificates', [CustomerPortalController::class, 'certificates'])->name('certificates');
        Route::get('/users/{userId}/activity', [CustomerPortalController::class, 'userActivity'])->name('user.activity');
    });
