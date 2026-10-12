<?php

use App\Domains\Auth\Http\Controllers\SessionAuthController;
use App\Domains\Invoice\Http\Controllers\PortalInvoiceController;
use App\Domains\Licensing\Http\Controllers\AdminLicenceController;
use App\Domains\Organization\Http\Controllers\CustomerPortalController;
use App\Domains\Platform\Http\Controllers\AdminController;
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
    ->group(function () {
        Route::get('/', [CustomerPortalController::class, 'dashboard'])->name('dashboard');
        // Invoices. {invoiceId} rather than {invoice}: route model binding
        // would lean on TenantScope alone, and the scope stands down in
        // console context - PortalInvoiceController names the organization in
        // the query instead, so a foreign id is unfindable rather than merely
        // unscoped.
        Route::get('/invoices', [PortalInvoiceController::class, 'index'])->name('invoices.index');
        Route::get('/invoices/{invoiceId}', [PortalInvoiceController::class, 'show'])->name('invoices.show');

        Route::get('/submissions', [CustomerPortalController::class, 'submissions'])->name('submissions');
        Route::get('/certificates', [CustomerPortalController::class, 'certificates'])->name('certificates');
        Route::get('/users/{userId}/activity', [CustomerPortalController::class, 'userActivity'])->name('user.activity');
    });
