<?php

use App\Domains\Auth\Http\Controllers\AccountController;
use App\Domains\Auth\Http\Controllers\EmailVerificationController;
use App\Domains\Auth\Http\Controllers\PasswordResetController;
use App\Domains\Auth\Http\Controllers\SessionAuthController;
use App\Domains\Auth\Http\Controllers\SignupController;
use App\Domains\Auth\Http\Controllers\TwoFactorController;
use App\Domains\Billing\Http\Controllers\AdminBillingController;
use App\Domains\Billing\Http\Controllers\PortalBillingController;
use App\Domains\Branding\Http\Controllers\AdminBrandingController;
use App\Domains\Branding\Http\Controllers\PortalBrandingController;
use App\Domains\Branding\Http\Controllers\ThemeAssetController;
use App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers\AdminAlertController;
use App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers\AdminKillSwitchController;
use App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers\PortalSubmissionController;
use App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers\PortalZatcaController;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Http\Controllers\PortalReceivedController;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Http\Controllers\PortalUaeInvoiceController;
use App\Domains\Invoice\Http\Controllers\PortalCustomerController;
use App\Domains\Invoice\Http\Controllers\PortalInvoiceController;
use App\Domains\Invoice\Http\Controllers\PortalInvoiceDocumentController;
use App\Domains\Invoice\Http\Controllers\PortalInvoiceDraftController;
use App\Domains\Invoice\Http\Controllers\PortalInvoiceFilingController;
use App\Domains\Invoice\Http\Controllers\PortalNumberingController;
use App\Domains\Invoice\Http\Controllers\PortalProductController;
use App\Domains\Invoice\Http\Controllers\PortalReportController;
use App\Domains\Licensing\Http\Controllers\AdminLicenceController;
use App\Domains\Organization\Http\Controllers\CustomerPortalController;
use App\Domains\Organization\Http\Controllers\InvitationController;
use App\Domains\Organization\Http\Controllers\PortalCompanyController;
use App\Domains\Organization\Http\Controllers\PortalTeamController;
use App\Domains\Platform\Http\Controllers\AdminAdminsController;
use App\Domains\Platform\Http\Controllers\AdminAuditController;
use App\Domains\Platform\Http\Controllers\AdminController;
use App\Domains\Platform\Http\Controllers\AdminCountryController;
use App\Domains\Platform\Http\Controllers\AdminFeatureController;
use App\Domains\Platform\Http\Controllers\AdminOrganizationReviewController;
use App\Domains\Platform\Http\Controllers\AdminSettingsController;
use App\Domains\Platform\Http\Controllers\AdminUserController;
use App\Domains\Support\Http\Controllers\AdminSupportController;
use App\Domains\Support\Http\Controllers\PortalSupportController;
use App\Domains\Webhook\Http\Controllers\AdminWebhookController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\LocaleController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// The privacy notice and the terms, which sign-up asks people to accept.
Route::get('/privacy', [LegalController::class, 'privacy'])->name('privacy');
Route::get('/terms', [LegalController::class, 'terms'])->name('terms');

// The interface language. Public: the sign-in page offers it too.
Route::post('/locale', LocaleController::class)->middleware('throttle:20,1')->name('locale');

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

// A business signing itself up, proving its email address, and getting back
// in after a forgotten password. Each form that sends mail or creates
// something is throttled.
Route::middleware('guest')->group(function () {
    Route::get('/register', [SignupController::class, 'create'])->name('register');
    Route::post('/register', [SignupController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('register.store');
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])
        ->middleware('throttle:5,1')
        ->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])
        ->middleware('throttle:10,1')
        ->name('password.update');
});

// An invitation to join an organization. The link works signed in or out:
// out, it creates the account it is for, or sends an existing one to sign in.
Route::get('/invitations/{token}', [InvitationController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{48}')
    ->middleware('throttle:20,1')
    ->name('invitations.show');
Route::post('/invitations/{token}/join', [InvitationController::class, 'join'])
    ->where('token', '[A-Za-z0-9]{48}')
    ->middleware(['guest', 'throttle:5,1'])
    ->name('invitations.join');
Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept'])
    ->where('token', '[A-Za-z0-9]{48}')
    ->middleware(['auth', 'throttle:10,1'])
    ->name('invitations.accept');

Route::middleware('auth')->group(function () {
    Route::get('/email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1')
        ->name('verification.send');
});

// My account: the signed-in person's own name, email address, password,
// sessions, second factor, and deleting it. Each change is throttled.
Route::prefix('account')->name('account.')->middleware(['auth', 'auth.session'])->group(function () {
    Route::get('/', [AccountController::class, 'show'])->name('show');
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('/name', [AccountController::class, 'name'])->name('name');
        Route::post('/email', [AccountController::class, 'email'])->name('email');
        Route::post('/password', [AccountController::class, 'password'])->name('password');
        Route::post('/sessions', [AccountController::class, 'sessions'])->name('sessions');
        Route::post('/two-factor/start', [AccountController::class, 'twoFactorStart'])->name('two-factor.start');
        Route::post('/two-factor', [AccountController::class, 'twoFactorConfirm'])->name('two-factor.confirm');
        Route::post('/two-factor/off', [AccountController::class, 'twoFactorOff'])->name('two-factor.off');
        Route::post('/delete', [AccountController::class, 'destroy'])->name('destroy');
    });
    Route::get('/email/{token}', [AccountController::class, 'confirmEmail'])
        ->where('token', '[A-Za-z0-9]{48}')
        ->name('email.confirm');
});

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
    // auth.session ends this session when the account's password changes
    // elsewhere - a reset is how someone takes an account back.
    // admin.can:console.view for every page; each action names its own
    // permission, which the admin's role has to cover (PlatformRole).
    ->middleware(['auth', 'auth.session', 'platform.admin', 'admin.can:console.view', 'admin.audit'])
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
    ->whereUuid(['id', 'licenceId', 'webhookId', 'logId', 'userId', 'ticketId', 'attachmentId', 'paymentId'])
    ->group(function () {
        Route::get('/', [AdminController::class, 'dashboard'])->name('dashboard');
        Route::get('/organizations', [AdminController::class, 'organizations'])->name('organizations');
        Route::get('/organizations/{id}', [AdminController::class, 'organizationDetail'])->name('organization.detail');
        // A plan's payments. Recording one renews the plan and invoices it.
        // Plans to chase before invoicing stops: payment is recorded by hand.
        Route::get('/renewals', [AdminBillingController::class, 'renewals'])->name('billing.renewals');
        Route::get('/organizations/{id}/billing', [AdminBillingController::class, 'show'])->name('billing.show');
        Route::post('/organizations/{id}/billing', [AdminBillingController::class, 'record'])->middleware('admin.can:billing.manage')->name('billing.record');
        Route::post('/organizations/{id}/billing/{paymentId}/invoice', [AdminBillingController::class, 'invoice'])->middleware('admin.can:billing.manage')->name('billing.invoice');
        Route::get('/organizations/{id}/billing/{paymentId}/pdf', [AdminBillingController::class, 'download'])->name('billing.download');
        // A business that signed itself up: approve it and assign its plan,
        // or turn it down with a reason. Either way its admins are emailed.
        Route::post('/organizations/{id}/approve', [AdminOrganizationReviewController::class, 'approve'])->middleware('admin.can:organizations.review')->name('organizations.approve');
        Route::post('/organizations/{id}/reject', [AdminOrganizationReviewController::class, 'reject'])->middleware('admin.can:organizations.review')->name('organizations.reject');
        // One feature off for one organization, or granted beyond its plan,
        // with a reason. Every role sees the Features section; changing it
        // takes features.manage.
        Route::post('/organizations/{id}/features', [AdminFeatureController::class, 'update'])->middleware('admin.can:features.manage')->name('organizations.features');
        // Support tickets. Everyone in the console reads them; answering
        // takes support.manage.
        Route::get('/support', [AdminSupportController::class, 'index'])->name('support.index');
        Route::get('/support/{ticketId}', [AdminSupportController::class, 'show'])->name('support.show');
        Route::get('/support/{ticketId}/attachments/{attachmentId}', [AdminSupportController::class, 'attachment'])->name('support.attachment');
        Route::post('/support/{ticketId}/reply', [AdminSupportController::class, 'reply'])->middleware('admin.can:support.manage')->name('support.reply');
        Route::post('/support/{ticketId}/assign', [AdminSupportController::class, 'assign'])->middleware('admin.can:support.manage')->name('support.assign');
        Route::post('/support/{ticketId}/status', [AdminSupportController::class, 'status'])->middleware('admin.can:support.manage')->name('support.status');

        Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
        Route::get('/users/{userId}', [AdminUserController::class, 'show'])->name('users.show');
        // Suspending locks someone out of everything at once, so it is a POST
        // behind a confirmation and a required reason.
        Route::post('/users/{userId}/suspend', [AdminUserController::class, 'suspend'])->middleware('admin.can:users.manage')->name('users.suspend');
        Route::post('/users/{userId}/reactivate', [AdminUserController::class, 'reactivate'])->middleware('admin.can:users.manage')->name('users.reactivate');
        Route::post('/users/{userId}/password-reset', [AdminUserController::class, 'sendPasswordReset'])->middleware(['admin.can:users.two_factor', 'throttle:10,1'])->name('users.password-reset');
        Route::post('/users/{userId}/reset-two-factor', [AdminUserController::class, 'resetTwoFactor'])->middleware('admin.can:users.two_factor')->name('users.reset-two-factor');
        Route::post('/users/{userId}/enrolment-link', [AdminUserController::class, 'issueEnrolmentLink'])->middleware('admin.can:users.two_factor')->name('users.enrolment-link');
        // Who runs the platform and in which role. Owners only.
        Route::get('/admins', [AdminAdminsController::class, 'index'])->middleware('admin.can:admins.manage')->name('admins.index');
        Route::post('/admins', [AdminAdminsController::class, 'grant'])->middleware('admin.can:admins.manage')->name('admins.grant');
        Route::post('/admins/{userId}/role', [AdminAdminsController::class, 'role'])->middleware('admin.can:admins.manage')->name('admins.role');
        Route::post('/admins/{userId}/revoke', [AdminAdminsController::class, 'revoke'])->middleware('admin.can:admins.manage')->name('admins.revoke');
        Route::get('/audit', [AdminAuditController::class, 'index'])->middleware('admin.can:audit.view')->name('audit');
        Route::get('/licences', [AdminLicenceController::class, 'index'])->name('licences.index');
        // Before the {licenceId} route below, or "create" is taken for an id
        // and the form never renders.
        Route::get('/licences/create', [AdminLicenceController::class, 'create'])->middleware('admin.can:licences.manage')->name('licences.create');
        Route::post('/licences', [AdminLicenceController::class, 'store'])->middleware('admin.can:licences.manage')->name('licences.store');
        Route::get('/licences/{licenceId}', [AdminLicenceController::class, 'show'])->name('licences.show');
        Route::post('/licences/{licenceId}/suspend', [AdminLicenceController::class, 'suspend'])->middleware('admin.can:licences.manage')->name('licences.suspend');
        Route::post('/licences/{licenceId}/reactivate', [AdminLicenceController::class, 'reactivate'])->middleware('admin.can:licences.manage')->name('licences.reactivate');
        Route::post('/licences/{licenceId}/revoke', [AdminLicenceController::class, 'revoke'])->middleware('admin.can:licences.manage')->name('licences.revoke');
        Route::post('/licences/{licenceId}/rotate', [AdminLicenceController::class, 'rotate'])->middleware('admin.can:licences.manage')->name('licences.rotate');
        Route::get('/alerts', [AdminAlertController::class, 'index'])->name('alerts');

        // The thresholds the dashboard judges by, and the certificate
        // warning schedule. Both were only changeable by shipping.
        Route::get('/settings', [AdminSettingsController::class, 'index'])->name('settings');

        // The platform's look: colours, name, logo and favicon.
        Route::get('/branding', [AdminBrandingController::class, 'edit'])->name('branding.edit');
        Route::post('/branding', [AdminBrandingController::class, 'update'])->middleware('admin.can:platform.configure')->name('branding.update');
        Route::post('/branding/import', [AdminBrandingController::class, 'import'])->middleware('admin.can:platform.configure')->name('branding.import');
        Route::get('/branding/export', [AdminBrandingController::class, 'export'])->name('branding.export');
        Route::post('/branding/reset', [AdminBrandingController::class, 'reset'])->middleware('admin.can:platform.configure')->name('branding.reset');
        Route::post('/settings', [AdminSettingsController::class, 'update'])->middleware('admin.can:platform.configure')->name('settings.update');

        // Which countries the platform issues in. One switch per country,
        // the same for every one; switching off stops new issuing there and
        // lets what was already issued finish.
        Route::get('/countries', [AdminCountryController::class, 'index'])->name('countries');
        Route::post('/countries', [AdminCountryController::class, 'update'])->middleware('admin.can:platform.configure')->name('countries.update');

        // The kill switches. Every one of these is a POST: they change what
        // the platform will do for every taxpayer on it, so none of them is
        // something a crawler or a prefetch should be able to follow.
        Route::get('/kill-switches', [AdminKillSwitchController::class, 'index'])->name('kill-switches');
        Route::post('/kill-switches/enable', [AdminKillSwitchController::class, 'enable'])->middleware('admin.can:platform.configure')->name('kill-switches.enable');
        Route::post('/kill-switches/disable', [AdminKillSwitchController::class, 'disable'])->middleware('admin.can:platform.configure')->name('kill-switches.disable');
        Route::post('/kill-switches/stop', [AdminKillSwitchController::class, 'stop'])->middleware('admin.can:platform.configure')->name('kill-switches.stop');
        Route::post('/kill-switches/resume', [AdminKillSwitchController::class, 'resume'])->middleware('admin.can:platform.configure')->name('kill-switches.resume');
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
            ->middleware('admin.can:operations.run')->name('webhooks.replay');
        Route::get('/queue', [AdminController::class, 'queue'])->name('queue');
        Route::get('/logs', [AdminController::class, 'logs'])->name('logs');

        // Actions
        Route::post('/queue/process', [AdminController::class, 'processQueue'])->middleware('admin.can:operations.run')->name('queue.process');
        Route::post('/queue/{id}/retry', [AdminController::class, 'retryQueueItem'])->middleware('admin.can:operations.run')->name('queue.retry');
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
    // Verified: a signed-up account proves its email address before it sees
    // the portal. Everyone with an account before sign-up existed counts as
    // verified (migration 0370).
    // org.writer: a viewer reads the portal and submits nothing.
    // org.two_factor: an organization that requires a second factor sends
    // anyone without one to set it up first.
    ->middleware(['auth', 'auth.session', 'verified', 'portal.tenant', 'org.two_factor', 'org.writer'])
    ->whereUuid(['invoiceId', 'userId', 'invitationId', 'ticketId', 'attachmentId', 'customerId', 'productId', 'paymentId', 'documentId'])
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
        // A note correcting an issued invoice, its form filled in from it.
        Route::get('/invoices/{invoiceId}/{note}', [PortalInvoiceDraftController::class, 'adjust'])
            ->whereIn('note', ['credit_note', 'debit_note'])
            ->name('invoices.adjust');
        Route::get('/invoices/{invoiceId}/print', [PortalInvoiceFilingController::class, 'print'])->name('invoices.print');
        // The PDF the buyer keeps, and sending it to them.
        Route::get('/invoices/{invoiceId}/pdf', [PortalInvoiceDocumentController::class, 'download'])->name('invoices.pdf');
        Route::post('/invoices/{invoiceId}/send', [PortalInvoiceDocumentController::class, 'send'])->middleware('throttle:20,1')->name('invoices.send');

        // UAE e-invoicing: the UAE invoice form's draft, the e-invoice an
        // issued invoice was sent as, and invoices other suppliers sent. A
        // UAE organization's only; anyone else gets a 404 (UaePortal).
        Route::post('/invoices/uae', [PortalUaeInvoiceController::class, 'store'])->name('invoices.uae.store');
        Route::get('/invoices/{invoiceId}/e-invoice', [PortalUaeInvoiceController::class, 'xml'])->name('invoices.xml');
        Route::get('/received', [PortalReceivedController::class, 'index'])->name('received.index');
        Route::get('/received/{documentId}', [PortalReceivedController::class, 'show'])->name('received.show');
        Route::get('/received/{documentId}/xml', [PortalReceivedController::class, 'xml'])->name('received.xml');

        Route::get('/submissions', [PortalSubmissionController::class, 'submissions'])->name('submissions');
        Route::get('/certificates', [PortalSubmissionController::class, 'certificates'])->name('certificates');
        Route::get('/users/{userId}/activity', [PortalSubmissionController::class, 'userActivity'])->name('user.activity');

        // The seller details on every invoice, which ZATCA onboarding needs
        // complete. Administrators only; the controller checks the role.
        Route::get('/company', [PortalCompanyController::class, 'edit'])->name('company.edit');
        Route::post('/company', [PortalCompanyController::class, 'update'])->name('company.update');
        Route::post('/company/security', [PortalCompanyController::class, 'security'])->name('company.security');
        Route::post('/company/pricing', [PortalCompanyController::class, 'pricing'])->name('company.pricing');

        // Customers and products, which the invoice form fills itself from.
        Route::get('/customers', [PortalCustomerController::class, 'index'])->name('customers.index');
        Route::get('/customers/new', [PortalCustomerController::class, 'create'])->name('customers.create');
        Route::post('/customers', [PortalCustomerController::class, 'store'])->name('customers.store');
        Route::get('/customers/{customerId}', [PortalCustomerController::class, 'edit'])->name('customers.edit');
        Route::post('/customers/{customerId}', [PortalCustomerController::class, 'update'])->name('customers.update');
        Route::post('/customers/{customerId}/archive', [PortalCustomerController::class, 'archive'])->name('customers.archive');
        Route::get('/products', [PortalProductController::class, 'index'])->name('products.index');
        Route::get('/products/new', [PortalProductController::class, 'create'])->name('products.create');
        Route::post('/products', [PortalProductController::class, 'store'])->name('products.store');
        Route::get('/products/{productId}', [PortalProductController::class, 'edit'])->name('products.edit');
        Route::post('/products/{productId}', [PortalProductController::class, 'update'])->name('products.update');
        Route::post('/products/{productId}/archive', [PortalProductController::class, 'archive'])->name('products.archive');

        // The period's sales as the VAT return groups them, and CSV exports.
        Route::get('/reports', [PortalReportController::class, 'index'])->name('reports');
        Route::get('/reports/invoices.csv', [PortalReportController::class, 'invoices'])->middleware('throttle:20,1')->name('reports.invoices');
        Route::get('/reports/vat.csv', [PortalReportController::class, 'vat'])->middleware('throttle:20,1')->name('reports.vat');
        // A day's close by device and branch, where the country counts per device.
        Route::get('/reports/close', [PortalReportController::class, 'close'])->name('reports.close');
        Route::get('/reports/close.csv', [PortalReportController::class, 'closeCsv'])->middleware('throttle:20,1')->name('reports.close.csv');

        // The plan, its usage and its payments. Administrators only.
        Route::get('/billing', [PortalBillingController::class, 'show'])->name('billing');
        Route::get('/billing/{paymentId}/invoice', [PortalBillingController::class, 'download'])->name('billing.invoice');

        // Support tickets: everyone in the organization sees its tickets.
        Route::get('/support', [PortalSupportController::class, 'index'])->name('support.index');
        Route::get('/support/new', [PortalSupportController::class, 'create'])->name('support.create');
        Route::post('/support', [PortalSupportController::class, 'store'])->middleware('throttle:10,1')->name('support.store');
        Route::get('/support/{ticketId}', [PortalSupportController::class, 'show'])->name('support.show');
        Route::post('/support/{ticketId}/reply', [PortalSupportController::class, 'reply'])->middleware('throttle:20,1')->name('support.reply');
        Route::post('/support/{ticketId}/resolve', [PortalSupportController::class, 'markResolved'])->name('support.resolve');
        Route::get('/support/{ticketId}/attachments/{attachmentId}', [PortalSupportController::class, 'attachment'])->name('support.attachment');

        // Connecting to ZATCA: OTP, compliance checks, production
        // certificate. Administrators only, and only once the company is
        // approved; the controller checks both. Each step reaches ZATCA, so
        // they are throttled.
        Route::get('/zatca', [PortalZatcaController::class, 'show'])->name('zatca.show');
        Route::post('/zatca/ccsid', [PortalZatcaController::class, 'ccsid'])->middleware('throttle:6,1')->name('zatca.ccsid');
        Route::post('/zatca/check', [PortalZatcaController::class, 'check'])->middleware('throttle:6,1')->name('zatca.check');
        Route::post('/zatca/production', [PortalZatcaController::class, 'production'])->middleware('throttle:6,1')->name('zatca.production');

        // The organization's people, and inviting more. Administrators only;
        // the controller checks the role.
        Route::get('/team', [PortalTeamController::class, 'index'])->name('team.index');
        Route::post('/team/invitations', [PortalTeamController::class, 'invite'])->middleware('throttle:20,1')->name('team.invite');
        Route::post('/team/invitations/{invitationId}/revoke', [PortalTeamController::class, 'revoke'])->name('team.revoke');
        Route::post('/team/members/{userId}/remove', [PortalTeamController::class, 'remove'])->name('team.remove');

        // How documents issued without a number are numbered. Administrators
        // only; the controller checks the role.
        Route::get('/numbering', [PortalNumberingController::class, 'edit'])->name('numbering.edit');
        Route::post('/numbering/{documentType}', [PortalNumberingController::class, 'update'])->name('numbering.update');

        // The organization's own look over the platform's. Administrators
        // of the organization only; the controller checks the role.
        Route::get('/branding', [PortalBrandingController::class, 'edit'])->name('branding.edit');
        Route::post('/branding', [PortalBrandingController::class, 'update'])->name('branding.update');
        Route::post('/branding/import', [PortalBrandingController::class, 'import'])->name('branding.import');
        Route::get('/branding/export', [PortalBrandingController::class, 'export'])->name('branding.export');
        Route::post('/branding/reset', [PortalBrandingController::class, 'reset'])->name('branding.reset');
    });
