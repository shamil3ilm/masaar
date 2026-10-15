<?php

use App\Domains\Auth\Http\Controllers\SessionAuthController;
use App\Domains\Auth\Http\Controllers\TwoFactorController;
use App\Domains\Branding\Http\Controllers\AdminBrandingController;
use App\Domains\Branding\Http\Controllers\PortalBrandingController;
use App\Domains\Branding\Http\Controllers\ThemeAssetController;
use App\Domains\Compliance\Fatoora\Http\Controllers\AdminAlertController;
use App\Domains\Compliance\Fatoora\Http\Controllers\AdminKillSwitchController;
use App\Domains\Invoice\Http\Controllers\PortalInvoiceController;
use App\Domains\Invoice\Http\Controllers\PortalInvoiceDraftController;
use App\Domains\Invoice\Http\Controllers\PortalInvoiceFilingController;
use App\Domains\Licensing\Http\Controllers\AdminLicenceController;
use App\Domains\Organization\Http\Controllers\CustomerPortalController;
use App\Domains\Organization\Http\Controllers\PortalCompanyController;
use App\Domains\Platform\Http\Controllers\AdminAuditController;
use App\Domains\Platform\Http\Controllers\AdminController;
use App\Domains\Platform\Http\Controllers\AdminSettingsController;
use App\Domains\Platform\Http\Controllers\AdminUserController;
use App\Domains\Webhook\Http\Controllers\AdminWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Theme (public)
|--------------------------------------------------------------------------
|
| The colours, logo and favicon every page loads, the sign-in page before
| anyone has signed in. {scope} is "platform" or an organization's id; an id
| with no theme of its own gets the platform's. Throttled, and a real UUID or
| nothing: every distinct id asked about used to leave a cache entry behind.
|
*/
// No .css on the end: nginx serves anything ending in .css as a static
// file and never reaches PHP, so the theme would have been a 404 there.
Route::get('/theme/{scope}/stylesheet', [ThemeAssetController::class, 'stylesheet'])
    ->where('scope', 'platform|[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}')
    ->middleware('throttle:300,1')
    ->name('theme.css');
Route::get('/theme/{scope}/logo', [ThemeAssetController::class, 'logo'])
    ->where('scope', 'platform|[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}')
    ->middleware('throttle:300,1')
    ->name('theme.logo');
Route::get('/theme/{scope}/favicon', [ThemeAssetController::class, 'favicon'])
    ->where('scope', 'platform|[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}')
    ->middleware('throttle:300,1')
    ->name('theme.favicon');

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

// A platform admin's second factor, between the password and the console.
// Throttled like the login itself, and per admin inside the controller.
Route::get('/login/two-factor', [TwoFactorController::class, 'showChallenge'])->name('login.two-factor');
Route::post('/login/two-factor', [TwoFactorController::class, 'challenge'])
    ->middleware('throttle:10,1')
    ->name('login.two-factor.attempt');
// A one-time enrolment link, issued by another admin or by masaar:enrolment-link.
Route::get('/login/two-factor/enrol/{token}', [TwoFactorController::class, 'enrol'])
    ->where('token', '[A-Za-z0-9]{48}')
    ->middleware('throttle:10,1')
    ->name('login.two-factor.enrol');
Route::get('/login/two-factor/setup', [TwoFactorController::class, 'showSetup'])->name('login.two-factor.setup');
Route::post('/login/two-factor/setup', [TwoFactorController::class, 'setup'])
    ->middleware('throttle:10,1')
    ->name('login.two-factor.confirm');

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
    // admin.audit records every change made through the console - see
    // AuditAdminAction. On the group, so a new admin action is on the record
    // without anyone having to remember it.
    ->middleware(['auth', 'platform.admin', 'admin.audit'])
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
    ->whereUuid(['id', 'licenceId', 'webhookId', 'logId', 'userId'])
    ->group(function () {
        Route::get('/', [AdminController::class, 'dashboard'])->name('dashboard');
        Route::get('/organizations', [AdminController::class, 'organizations'])->name('organizations');
        Route::get('/organizations/{id}', [AdminController::class, 'organizationDetail'])->name('organization.detail');
        Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
        Route::get('/users/{userId}', [AdminUserController::class, 'show'])->name('users.show');
        // Suspending locks someone out of everything at once, so it is a POST
        // behind a confirmation and a required reason.
        Route::post('/users/{userId}/suspend', [AdminUserController::class, 'suspend'])->name('users.suspend');
        Route::post('/users/{userId}/reactivate', [AdminUserController::class, 'reactivate'])->name('users.reactivate');
        Route::post('/users/{userId}/reset-two-factor', [AdminUserController::class, 'resetTwoFactor'])->name('users.reset-two-factor');
        Route::post('/users/{userId}/enrolment-link', [AdminUserController::class, 'issueEnrolmentLink'])->name('users.enrolment-link');
        Route::get('/audit', [AdminAuditController::class, 'index'])->name('audit');
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

        // The thresholds the dashboard judges by, and the certificate
        // warning schedule. Both were only changeable by shipping.
        Route::get('/settings', [AdminSettingsController::class, 'index'])->name('settings');

        // The platform's look: colours, name, logo and favicon.
        Route::get('/branding', [AdminBrandingController::class, 'edit'])->name('branding.edit');
        Route::post('/branding', [AdminBrandingController::class, 'update'])->name('branding.update');
        Route::post('/branding/import', [AdminBrandingController::class, 'import'])->name('branding.import');
        Route::get('/branding/export', [AdminBrandingController::class, 'export'])->name('branding.export');
        Route::post('/branding/reset', [AdminBrandingController::class, 'reset'])->name('branding.reset');
        Route::post('/settings', [AdminSettingsController::class, 'update'])->name('settings.update');

        // The kill switches. Every one of these is a POST: they change what
        // the platform will do for every taxpayer on it, so none of them is
        // something a crawler or a prefetch should be able to follow.
        Route::get('/kill-switches', [AdminKillSwitchController::class, 'index'])->name('kill-switches');
        Route::post('/kill-switches/enable', [AdminKillSwitchController::class, 'enable'])->name('kill-switches.enable');
        Route::post('/kill-switches/disable', [AdminKillSwitchController::class, 'disable'])->name('kill-switches.disable');
        Route::post('/kill-switches/stop', [AdminKillSwitchController::class, 'stop'])->name('kill-switches.stop');
        Route::post('/kill-switches/resume', [AdminKillSwitchController::class, 'resume'])->name('kill-switches.resume');
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
| Customer Portal Routes
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

        // The seller details on every invoice, which ZATCA onboarding needs
        // complete. Administrators only; the controller checks the role.
        Route::get('/company', [PortalCompanyController::class, 'edit'])->name('company.edit');
        Route::post('/company', [PortalCompanyController::class, 'update'])->name('company.update');

        // The organization's own look over the platform's. Administrators
        // of the organization only; the controller checks the role.
        Route::get('/branding', [PortalBrandingController::class, 'edit'])->name('branding.edit');
        Route::post('/branding', [PortalBrandingController::class, 'update'])->name('branding.update');
        Route::post('/branding/import', [PortalBrandingController::class, 'import'])->name('branding.import');
        Route::get('/branding/export', [PortalBrandingController::class, 'export'])->name('branding.export');
        Route::post('/branding/reset', [PortalBrandingController::class, 'reset'])->name('branding.reset');
    });
