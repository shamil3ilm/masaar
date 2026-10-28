<?php

use App\Domains\Audit\Http\Controllers\PortalActivityController;
use App\Domains\Auth\Http\Controllers\AccountController;
use App\Domains\Auth\Http\Controllers\AdminInvitationController;
use App\Domains\Auth\Http\Controllers\EmailVerificationController;
use App\Domains\Auth\Http\Controllers\PasswordResetController;
use App\Domains\Auth\Http\Controllers\SessionAuthController;
use App\Domains\Auth\Http\Controllers\SignupController;
use App\Domains\Auth\Http\Controllers\TwoFactorController;
use App\Domains\Billing\Http\Controllers\AdminBillingController;
use App\Domains\Billing\Http\Controllers\PortalBillingController;
use App\Domains\Books\Http\Controllers\PortalBooksController;
use App\Domains\Books\Http\Controllers\PortalBookSupplierController;
use App\Domains\Books\Http\Controllers\PortalPurchaseController;
use App\Domains\Books\Http\Controllers\PortalReceivablesController;
use App\Domains\Books\Http\Controllers\PortalSalesReportController;
use App\Domains\Books\Http\Controllers\PortalVatReturnController;
use App\Domains\Books\Http\Controllers\PortalVoucherController;
use App\Domains\Branding\Http\Controllers\AdminBrandingController;
use App\Domains\Branding\Http\Controllers\PortalBrandingController;
use App\Domains\Branding\Http\Controllers\ThemeAssetController;
use App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers\AdminKillSwitchController;
use App\Domains\Invoice\Http\Controllers\AdminInvoiceTemplateController;
use App\Domains\Invoice\Http\Controllers\InvoiceLinkController;
use App\Domains\Invoice\Http\Controllers\PortalCatalogueLookupController;
use App\Domains\Invoice\Http\Controllers\PortalContractController;
use App\Domains\Invoice\Http\Controllers\PortalCustomerController;
use App\Domains\Invoice\Http\Controllers\PortalImportController;
use App\Domains\Invoice\Http\Controllers\PortalInsuranceSplitController;
use App\Domains\Invoice\Http\Controllers\PortalInvoiceAdjustmentController;
use App\Domains\Invoice\Http\Controllers\PortalInvoiceController;
use App\Domains\Invoice\Http\Controllers\PortalInvoiceDocumentController;
use App\Domains\Invoice\Http\Controllers\PortalInvoiceDraftController;
use App\Domains\Invoice\Http\Controllers\PortalInvoiceFilingController;
use App\Domains\Invoice\Http\Controllers\PortalInvoicePreviewController;
use App\Domains\Invoice\Http\Controllers\PortalInvoicePrintController;
use App\Domains\Invoice\Http\Controllers\PortalInvoiceTemplateController;
use App\Domains\Invoice\Http\Controllers\PortalNoteApprovalController;
use App\Domains\Invoice\Http\Controllers\PortalNumberingController;
use App\Domains\Invoice\Http\Controllers\PortalProductController;
use App\Domains\Invoice\Http\Controllers\PortalQrCodeController;
use App\Domains\Invoice\Http\Controllers\PortalReportController;
use App\Domains\Invoice\Http\Controllers\PortalScheduleController;
use App\Domains\Invoice\Http\Controllers\PortalSummaryInvoiceController;
use App\Domains\Licensing\Http\Controllers\AdminLicenceController;
use App\Domains\Licensing\Http\Controllers\PortalApiKeyController;
use App\Domains\Licensing\Http\Controllers\PortalRequestLogController;
use App\Domains\Organization\Http\Controllers\AdminBusinessTypeController;
use App\Domains\Organization\Http\Controllers\CustomerPortalController;
use App\Domains\Organization\Http\Controllers\InvitationController;
use App\Domains\Organization\Http\Controllers\PortalBranchController;
use App\Domains\Organization\Http\Controllers\PortalCompanyController;
use App\Domains\Organization\Http\Controllers\PortalPreferencesController;
use App\Domains\Organization\Http\Controllers\PortalPrintingController;
use App\Domains\Organization\Http\Controllers\PortalSettingsController;
use App\Domains\Organization\Http\Controllers\PortalTeamController;
use App\Domains\Organization\Http\Controllers\PortalWorkingBranchController;
use App\Domains\Platform\Http\Controllers\AdminAdminsController;
use App\Domains\Platform\Http\Controllers\AdminApprovalPolicyController;
use App\Domains\Platform\Http\Controllers\AdminApprovalsController;
use App\Domains\Platform\Http\Controllers\AdminAuditController;
use App\Domains\Platform\Http\Controllers\AdminContactMessageController;
use App\Domains\Platform\Http\Controllers\AdminController;
use App\Domains\Platform\Http\Controllers\AdminCountryController;
use App\Domains\Platform\Http\Controllers\AdminCustomizationController;
use App\Domains\Platform\Http\Controllers\AdminFeatureController;
use App\Domains\Platform\Http\Controllers\AdminLanguageController;
use App\Domains\Platform\Http\Controllers\AdminOrganizationReviewController;
use App\Domains\Platform\Http\Controllers\AdminOwnershipController;
use App\Domains\Platform\Http\Controllers\AdminRolesController;
use App\Domains\Platform\Http\Controllers\AdminSettingsController;
use App\Domains\Platform\Http\Controllers\AdminUserController;
use App\Domains\Platform\Http\Controllers\AdminWebsiteSectionController;
use App\Domains\Platform\Http\Controllers\AdminWebsiteSeoController;
use App\Domains\Platform\Http\Controllers\AdminWebsiteTestimonialController;
use App\Domains\Platform\Http\Controllers\AppManifestController;
use App\Domains\Platform\Http\Controllers\ContactController;
use App\Domains\Platform\Http\Controllers\DeveloperController;
use App\Domains\Platform\Http\Controllers\SavedListViewController;
use App\Domains\Platform\Http\Controllers\WebsiteAssetController;
use App\Domains\Platform\Http\Controllers\WebsiteSeoController;
use App\Domains\Referral\Http\Controllers\AdminReferralController;
use App\Domains\Referral\Http\Controllers\AdminReferralSettingsController;
use App\Domains\Referral\Http\Controllers\PortalReferralController;
use App\Domains\Support\Http\Controllers\AdminBoardController;
use App\Domains\Support\Http\Controllers\AdminRequestCategoryController;
use App\Domains\Support\Http\Controllers\AdminSupportController;
use App\Domains\Support\Http\Controllers\PortalBoardController;
use App\Domains\Support\Http\Controllers\PortalSupportController;
use App\Domains\Webhook\Http\Controllers\AdminWebhookController;
use App\Domains\Webhook\Http\Controllers\PortalWebhookController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\LocaleController;
use App\Http\Middleware\WebOnlyWithSession;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

// What a search engine reads: which public pages there are, each in both
// languages (?lang=en, ?lang=ar), and where the private areas begin.
Route::get('/sitemap.xml', [WebsiteSeoController::class, 'sitemap'])->middleware('throttle:60,1')->name('sitemap');
Route::get('/robots.txt', [WebsiteSeoController::class, 'robots'])->middleware('throttle:60,1')->name('robots');

// What a phone calls the product on its home screen, and the icons it uses.
Route::get('/manifest.webmanifest', AppManifestController::class)->middleware('throttle:60,1')->name('app.manifest');

// The picture a shared link to the site shows, when an admin has set one.
Route::get('/website/share-image', [WebsiteAssetController::class, 'shareImage'])->middleware('throttle:300,1')->name('website.share-image');

// The home page's contact form. CSRF like every web form; past that, no
// CAPTCHA: ContactGuard's honeypot, time trap, limits per address and per
// email, and link and keyword checks. The throttle is the backstop for a
// flood the guard's hourly limits are too slow to stop.
Route::post('/contact', [ContactController::class, 'store'])->middleware('throttle:10,1')->name('contact.store');

// A published testimonial's logo, for the home page. Images only, checked
// on upload (WebsiteImages) and served sandboxed.
Route::get('/website/testimonials/{testimonialId}/logo', [WebsiteAssetController::class, 'testimonialLogo'])
    ->whereUuid('testimonialId')
    ->middleware('throttle:300,1')
    ->name('website.testimonials.logo');

// The developer page: getting a key, the quick start and the API reference,
// from the OpenAPI description. Public, read-only, nothing of anyone's.
Route::get('/developers', DeveloperController::class)->middleware('throttle:60,1')->name('developers');

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
// An invoice template's colour, for the print view (ThemeAssetController::accent).
Route::get('/theme/print-accent/{accent}', [ThemeAssetController::class, 'accent'])
    ->where('accent', '[0-9a-fA-F]{6}')
    ->middleware('throttle:300,1')
    ->name('theme.print-accent');
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

// An invitation to be a platform admin, by email. As the organization's: it
// works signed in or out, and makes the account it is for.
Route::get('/invitations/console/{token}', [AdminInvitationController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{48}')
    ->middleware('throttle:20,1')
    ->name('admin-invitations.show');
Route::post('/invitations/console/{token}/join', [AdminInvitationController::class, 'join'])
    ->where('token', '[A-Za-z0-9]{48}')
    ->middleware(['guest', 'throttle:5,1'])
    ->name('admin-invitations.join');
Route::post('/invitations/console/{token}/accept', [AdminInvitationController::class, 'accept'])
    ->where('token', '[A-Za-z0-9]{48}')
    ->middleware(['auth', 'throttle:10,1'])
    ->name('admin-invitations.accept');

// An issued invoice opened from its link - the QR code on it - without
// signing in. The token is the only key; the throttle limits guessing.
Route::get('/i/{token}', [InvoiceLinkController::class, 'show'])
    ->where('token', '[A-Za-z0-9_-]{43}')
    ->middleware('throttle:30,1')
    ->name('invoice-link.show');
Route::get('/i/{token}/pdf', [InvoiceLinkController::class, 'pdf'])
    ->where('token', '[A-Za-z0-9_-]{43}')
    ->middleware('throttle:10,1')
    ->name('invoice-link.pdf');

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
    // An operator's saved views on the console's lists: their own
    // preference, which changes nothing on the platform.
    Route::middleware('throttle:30,1')->whereUuid(['viewId'])->group(function () {
        Route::post('/views', [SavedListViewController::class, 'adminStore'])->name('views.store');
        Route::post('/views/{viewId}/delete', [SavedListViewController::class, 'adminDestroy'])->name('views.destroy');
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
// A one-time enrolment link, issued by another admin or by platform:enrolment-link.
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
| Admin Dashboard Routes (platform internal)
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
    ->whereUuid(['id', 'licenceId', 'webhookId', 'logId', 'userId', 'ticketId', 'attachmentId', 'paymentId', 'requestId'])
    ->group(function () {
        Route::get('/', [AdminController::class, 'dashboard'])->name('dashboard');
        Route::get('/organizations', [AdminController::class, 'organizations'])->name('organizations');
        Route::get('/organizations/export.csv', [AdminController::class, 'exportOrganizations'])->middleware('throttle:20,1')->name('organizations.export');
        Route::get('/organizations/{id}', [AdminController::class, 'organizationDetail'])->name('organization.detail');
        // A plan's payments. Recording one renews the plan and invoices it.
        // Plans to chase before invoicing stops: payment is recorded by hand.
        Route::get('/renewals', [AdminBillingController::class, 'renewals'])->name('billing.renewals');
        Route::get('/organizations/{id}/billing', [AdminBillingController::class, 'show'])->name('billing.show');
        Route::post('/organizations/{id}/billing', [AdminBillingController::class, 'record'])->middleware('admin.can:billing.manage')->name('billing.record');
        Route::post('/organizations/{id}/billing/{paymentId}/invoice', [AdminBillingController::class, 'invoice'])->middleware('admin.can:billing.manage')->name('billing.invoice');
        Route::get('/organizations/{id}/billing/{paymentId}/pdf', [AdminBillingController::class, 'download'])->name('billing.download');
        // Finance: how the platform bills its own customers.
        require __DIR__.'/finance.php';
        // A business that signed itself up: approve it and assign its plan,
        // or turn it down with a reason. Either way its admins are emailed.
        Route::post('/organizations/{id}/approve', [AdminOrganizationReviewController::class, 'approve'])->middleware('admin.can:organizations.review')->name('organizations.approve');
        Route::post('/organizations/{id}/reject', [AdminOrganizationReviewController::class, 'reject'])->middleware('admin.can:organizations.review')->name('organizations.reject');
        // Suspending stops new issuing with a reason; the organization keeps
        // its data and exports, and what it issued keeps being filed.
        Route::post('/organizations/{id}/suspend', [AdminOrganizationReviewController::class, 'suspend'])->middleware('admin.can:organizations.review')->name('organizations.suspend');
        Route::post('/organizations/{id}/reactivate', [AdminOrganizationReviewController::class, 'reactivate'])->middleware('admin.can:organizations.review')->name('organizations.reactivate');
        // One feature off for one organization, or granted beyond its plan,
        // with a reason. Every role sees the Features section; changing it
        // takes features.manage.
        Route::post('/organizations/{id}/features', [AdminFeatureController::class, 'update'])->middleware('admin.can:features.manage')->name('organizations.features');
        // Where its country requires Arabic: the names it still lacks, and a
        // grace period - warn instead of refuse until a date - with a reason.
        Route::get('/organizations/{id}/missing-arabic', [AdminLanguageController::class, 'missing'])->name('organizations.missing-arabic');
        Route::post('/organizations/{id}/language-grace', [AdminLanguageController::class, 'grace'])->middleware('admin.can:features.manage')->name('organizations.language-grace');
        // Finance › Referrals: the programme's report and referrals, its terms
        // and a partner's own. Everyone in the console reads them; approving,
        // adjusting, reversing and the terms take billing.manage.
        Route::prefix('/finance/referrals')->name('referrals.')->whereUuid(['referralId', 'rewardId'])->group(function () {
            Route::get('/', [AdminReferralController::class, 'index'])->name('index');
            Route::get('/settings', [AdminReferralSettingsController::class, 'edit'])->name('settings');
            Route::post('/settings', [AdminReferralSettingsController::class, 'update'])->middleware('admin.can:billing.manage')->name('settings.update');
            Route::get('/organizations/{id}', [AdminReferralSettingsController::class, 'organization'])->name('organization');
            Route::post('/organizations/{id}', [AdminReferralSettingsController::class, 'updateOrganization'])->middleware('admin.can:billing.manage')->name('organization.update');
            Route::post('/organizations/{id}/reset', [AdminReferralSettingsController::class, 'resetOrganization'])->middleware('admin.can:billing.manage')->name('organization.reset');
            Route::get('/{referralId}', [AdminReferralController::class, 'show'])->name('show');
            Route::post('/{referralId}/approve', [AdminReferralController::class, 'approve'])->middleware('admin.can:billing.manage')->name('approve');
            Route::post('/{referralId}/reverse', [AdminReferralController::class, 'reverse'])->middleware('admin.can:billing.manage')->name('reverse');
            Route::post('/{referralId}/rewards/{rewardId}', [AdminReferralController::class, 'adjust'])->middleware('admin.can:billing.manage')->name('adjust');
        });

        // Support tickets. Everyone in the console reads them; answering
        // takes support.manage.
        Route::get('/support', [AdminSupportController::class, 'index'])->name('support.index');
        Route::get('/support/{ticketId}', [AdminSupportController::class, 'show'])->name('support.show');
        Route::get('/support/{ticketId}/attachments/{attachmentId}', [AdminSupportController::class, 'attachment'])->name('support.attachment');
        Route::post('/support/{ticketId}/reply', [AdminSupportController::class, 'reply'])->middleware('admin.can:support.manage')->name('support.reply');
        Route::post('/support/{ticketId}/assign', [AdminSupportController::class, 'assign'])->middleware('admin.can:support.manage')->name('support.assign');
        Route::post('/support/{ticketId}/status', [AdminSupportController::class, 'status'])->middleware('admin.can:support.manage')->name('support.status');
        // A customization or a feature request moved on, with a note the
        // customer reads; a customization's agreed work and price, which
        // billing takes it at.
        Route::post('/support/{ticketId}/stage', [AdminSupportController::class, 'stage'])->middleware('admin.can:support.manage')->name('support.stage');
        Route::post('/support/{ticketId}/customization', [AdminSupportController::class, 'customization'])->middleware('admin.can:billing.manage')->name('support.customization');

        // The feature board: feature requests triaged into items worded with
        // no company named, published for every customer, moved on with
        // public notes, merged when two are one. Everyone in the console
        // reads it; triage takes support.manage.
        Route::get('/board', [AdminBoardController::class, 'index'])->name('board.index');
        Route::post('/support/{ticketId}/board', [AdminBoardController::class, 'store'])->middleware('admin.can:support.manage')->name('board.store');
        Route::post('/support/{ticketId}/board/link', [AdminBoardController::class, 'link'])->middleware('admin.can:support.manage')->name('board.link');
        Route::post('/support/{ticketId}/convert', [AdminBoardController::class, 'convert'])->middleware('admin.can:support.manage')->name('board.convert');
        Route::prefix('/board/{itemId}')->whereUuid('itemId')->group(function () {
            Route::get('/', [AdminBoardController::class, 'show'])->name('board.show');
            Route::post('/', [AdminBoardController::class, 'update'])->middleware('admin.can:support.manage')->name('board.update');
            Route::post('/publish', [AdminBoardController::class, 'publish'])->middleware('admin.can:support.manage')->name('board.publish');
            Route::post('/stage', [AdminBoardController::class, 'stage'])->middleware('admin.can:support.manage')->name('board.stage');
            Route::post('/merge', [AdminBoardController::class, 'merge'])->middleware('admin.can:support.manage')->name('board.merge');
        });

        // Website › Messages: the home page's contact form. Reading and
        // answering both take support.manage - answering people is support's
        // work, and the senders' details are theirs to keep.
        Route::prefix('/website/messages')->name('website.messages.')->middleware('admin.can:support.manage')->whereUuid('messageId')->group(function () {
            Route::get('/', [AdminContactMessageController::class, 'index'])->name('index');
            Route::get('/{messageId}', [AdminContactMessageController::class, 'show'])->name('show');
            Route::post('/{messageId}/replied', [AdminContactMessageController::class, 'replied'])->name('replied');
            Route::post('/{messageId}/archive', [AdminContactMessageController::class, 'archive'])->name('archive');
            Route::post('/{messageId}/restore', [AdminContactMessageController::class, 'restore'])->name('restore');
        });

        // Website › the public site's words: the home page's sections and
        // testimonials. Its own permission (website.manage), held by the
        // owner and operations.
        Route::prefix('/website')->name('website.')->middleware('admin.can:website.manage')->group(function () {
            Route::get('/sections', [AdminWebsiteSectionController::class, 'index'])->name('sections.index');
            Route::get('/sections/{sectionKey}/edit', [AdminWebsiteSectionController::class, 'edit'])->where('sectionKey', '[a-z_]+')->name('sections.edit');
            Route::post('/sections/{sectionKey}', [AdminWebsiteSectionController::class, 'update'])->where('sectionKey', '[a-z_]+')->name('sections.update');
            Route::post('/sections/{sectionKey}/visibility', [AdminWebsiteSectionController::class, 'visibility'])->where('sectionKey', '[a-z_]+')->name('sections.visibility');
            Route::post('/sections/{sectionKey}/move', [AdminWebsiteSectionController::class, 'move'])->where('sectionKey', '[a-z_]+')->name('sections.move');
            Route::post('/sections/{sectionKey}/reset', [AdminWebsiteSectionController::class, 'reset'])->where('sectionKey', '[a-z_]+')->name('sections.reset');
            Route::get('/seo', [AdminWebsiteSeoController::class, 'edit'])->name('seo.edit');
            Route::post('/seo', [AdminWebsiteSeoController::class, 'update'])->name('seo.update');
            Route::get('/testimonials', [AdminWebsiteTestimonialController::class, 'index'])->name('testimonials.index');
            Route::get('/testimonials/create', [AdminWebsiteTestimonialController::class, 'create'])->name('testimonials.create');
            Route::post('/testimonials', [AdminWebsiteTestimonialController::class, 'store'])->name('testimonials.store');
            Route::get('/testimonials/{testimonialId}/edit', [AdminWebsiteTestimonialController::class, 'edit'])->whereUuid('testimonialId')->name('testimonials.edit');
            Route::post('/testimonials/{testimonialId}', [AdminWebsiteTestimonialController::class, 'update'])->whereUuid('testimonialId')->name('testimonials.update');
            Route::post('/testimonials/{testimonialId}/visibility', [AdminWebsiteTestimonialController::class, 'visibility'])->whereUuid('testimonialId')->name('testimonials.visibility');
            Route::post('/testimonials/{testimonialId}/move', [AdminWebsiteTestimonialController::class, 'move'])->whereUuid('testimonialId')->name('testimonials.move');
            Route::post('/testimonials/{testimonialId}/delete', [AdminWebsiteTestimonialController::class, 'destroy'])->whereUuid('testimonialId')->name('testimonials.destroy');
        });

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
        // By email, whether or not the address has an account yet.
        Route::post('/admins/invitations', [AdminAdminsController::class, 'invite'])->middleware(['admin.can:admins.manage', 'throttle:20,1'])->name('admins.invite');
        Route::post('/admins/invitations/{invitationId}/withdraw', [AdminAdminsController::class, 'withdrawInvitation'])->middleware('admin.can:admins.manage')->whereUuid('invitationId')->name('admins.invitations.withdraw');
        Route::post('/admins/{userId}/role', [AdminAdminsController::class, 'role'])->middleware('admin.can:admins.manage')->name('admins.role');
        Route::post('/admins/{userId}/revoke', [AdminAdminsController::class, 'revoke'])->middleware('admin.can:admins.manage')->name('admins.revoke');
        // The platform's one owner hands ownership on: offered by the owner,
        // accepted by the admin it is offered to, and only then does the old
        // owner step down, to the role they chose.
        Route::post('/ownership', [AdminOwnershipController::class, 'offer'])->middleware('admin.can:admins.manage')->name('ownership.offer');
        Route::post('/ownership/{transferId}/withdraw', [AdminOwnershipController::class, 'withdraw'])->middleware('admin.can:admins.manage')->whereUuid('transferId')->name('ownership.withdraw');
        Route::post('/ownership/{transferId}/accept', [AdminOwnershipController::class, 'accept'])->middleware('admin.can:approvals.decide')->whereUuid('transferId')->name('ownership.accept');
        // Admin roles: the four system roles and custom ones from the
        // permission set. Owners only; held for approval like access is.
        Route::get('/roles', [AdminRolesController::class, 'index'])->middleware('admin.can:admins.manage')->name('roles.index');
        Route::post('/roles', [AdminRolesController::class, 'store'])->middleware('admin.can:admins.manage')->name('roles.store');
        Route::post('/roles/{key}', [AdminRolesController::class, 'update'])->middleware('admin.can:admins.manage')->name('roles.update');
        Route::post('/roles/{key}/delete', [AdminRolesController::class, 'destroy'])->middleware('admin.can:admins.manage')->name('roles.destroy');
        Route::get('/audit', [AdminAuditController::class, 'index'])->middleware('admin.can:audit.view')->name('audit');
        Route::get('/audit/export.csv', [AdminAuditController::class, 'export'])->middleware(['admin.can:audit.view', 'throttle:20,1'])->name('audit.export');
        // Maker-checker: what waits for a second admin, and deciding it.
        // Who may decide one is its policy's; approvals.decide is the least.
        Route::get('/approvals', [AdminApprovalsController::class, 'index'])->name('approvals.index');
        Route::post('/approvals/notifications', [AdminApprovalsController::class, 'notifications'])->middleware('admin.can:approvals.decide')->name('approvals.notifications');
        Route::get('/approvals/{requestId}', [AdminApprovalsController::class, 'show'])->name('approvals.show');
        Route::post('/approvals/{requestId}/approve', [AdminApprovalsController::class, 'approve'])->middleware('admin.can:approvals.decide')->name('approvals.approve');
        Route::post('/approvals/{requestId}/reject', [AdminApprovalsController::class, 'reject'])->middleware('admin.can:approvals.decide')->name('approvals.reject');
        Route::post('/approvals/{requestId}/withdraw', [AdminApprovalsController::class, 'withdraw'])->middleware('admin.can:approvals.decide')->name('approvals.withdraw');
        Route::get('/licences', [AdminLicenceController::class, 'index'])->name('licences.index');
        Route::get('/licences/export.csv', [AdminLicenceController::class, 'export'])->middleware('throttle:20,1')->name('licences.export');
        // Before the {licenceId} route below, or "create" is taken for an id
        // and the form never renders.
        Route::get('/licences/create', [AdminLicenceController::class, 'create'])->middleware('admin.can:licences.manage')->name('licences.create');
        Route::post('/licences', [AdminLicenceController::class, 'store'])->middleware('admin.can:licences.manage')->name('licences.store');
        Route::get('/licences/{licenceId}', [AdminLicenceController::class, 'show'])->name('licences.show');
        Route::post('/licences/{licenceId}/allowances', [AdminLicenceController::class, 'allowances'])->middleware('admin.can:licences.manage')->name('licences.allowances');
        Route::post('/licences/{licenceId}/suspend', [AdminLicenceController::class, 'suspend'])->middleware('admin.can:licences.manage')->name('licences.suspend');
        Route::post('/licences/{licenceId}/reactivate', [AdminLicenceController::class, 'reactivate'])->middleware('admin.can:licences.manage')->name('licences.reactivate');
        Route::post('/licences/{licenceId}/revoke', [AdminLicenceController::class, 'revoke'])->middleware('admin.can:licences.manage')->name('licences.revoke');
        Route::post('/licences/{licenceId}/rotate', [AdminLicenceController::class, 'rotate'])->middleware('admin.can:licences.manage')->name('licences.rotate');

        // The thresholds the dashboard judges by, and the certificate
        // warning schedule. Both were only changeable by shipping.
        Route::get('/settings', [AdminSettingsController::class, 'index'])->name('settings');
        // Which actions wait for approval. Changing a policy is itself held
        // for approval once any policy is on.
        Route::get('/settings/approvals', [AdminApprovalPolicyController::class, 'index'])->name('approval-policies.index');
        Route::post('/settings/approvals-bypass', [AdminApprovalPolicyController::class, 'bypass'])->middleware('admin.can:admins.manage')->name('approval-policies.bypass');
        Route::post('/settings/approvals/{action}', [AdminApprovalPolicyController::class, 'update'])->middleware('admin.can:platform.configure')->name('approval-policies.update');
        // What an organization can say it is, and the defaults each suggests.
        Route::get('/settings/business-types', [AdminBusinessTypeController::class, 'index'])->name('business-types.index');
        Route::post('/settings/business-types', [AdminBusinessTypeController::class, 'store'])->middleware('admin.can:platform.configure')->name('business-types.store');
        Route::post('/settings/business-types/{key}', [AdminBusinessTypeController::class, 'update'])->middleware('admin.can:platform.configure')->name('business-types.update');
        Route::post('/settings/business-types/{key}/retire', [AdminBusinessTypeController::class, 'retire'])->middleware('admin.can:platform.configure')->name('business-types.retire');
        Route::post('/settings/business-types/{key}/move', [AdminBusinessTypeController::class, 'move'])->middleware('admin.can:platform.configure')->name('business-types.move');
        // What a feature request can be about, in both languages.
        Route::get('/settings/request-categories', [AdminRequestCategoryController::class, 'index'])->name('request-categories.index');
        Route::post('/settings/request-categories', [AdminRequestCategoryController::class, 'store'])->middleware('admin.can:platform.configure')->name('request-categories.store');
        Route::post('/settings/request-categories/{key}', [AdminRequestCategoryController::class, 'update'])->middleware('admin.can:platform.configure')->name('request-categories.update');
        Route::post('/settings/request-categories/{key}/retire', [AdminRequestCategoryController::class, 'retire'])->middleware('admin.can:platform.configure')->name('request-categories.retire');
        Route::post('/settings/request-categories/{key}/move', [AdminRequestCategoryController::class, 'move'])->middleware('admin.can:platform.configure')->name('request-categories.move');

        // The platform's look: colours, name, logo and favicon.
        Route::get('/branding', [AdminBrandingController::class, 'edit'])->name('branding.edit');
        Route::post('/branding', [AdminBrandingController::class, 'update'])->middleware('admin.can:platform.configure')->name('branding.update');
        Route::post('/branding/import', [AdminBrandingController::class, 'import'])->middleware('admin.can:platform.configure')->name('branding.import');
        Route::get('/branding/export', [AdminBrandingController::class, 'export'])->name('branding.export');
        Route::post('/branding/reset', [AdminBrandingController::class, 'reset'])->middleware('admin.can:platform.configure')->name('branding.reset');
        // The platform's invoice template, which every organization's follows.
        Route::get('/branding/invoice-template', [AdminInvoiceTemplateController::class, 'edit'])->name('invoice-template.edit');
        Route::post('/branding/invoice-template', [AdminInvoiceTemplateController::class, 'update'])->middleware('admin.can:platform.configure')->name('invoice-template.update');
        Route::post('/branding/invoice-template/reset', [AdminInvoiceTemplateController::class, 'reset'])->middleware('admin.can:platform.configure')->name('invoice-template.reset');
        Route::get('/branding/invoice-template/preview', [AdminInvoiceTemplateController::class, 'preview'])->middleware('throttle:120,1')->name('invoice-template.preview');
        Route::get('/branding/invoice-template/sample', [AdminInvoiceTemplateController::class, 'samplePdf'])->middleware('throttle:30,1')->name('invoice-template.sample');

        // The design system: every token and component, drawn by the
        // components themselves, in light and dark and in both directions.
        Route::view('/design-system', 'admin.design-system')->name('design-system');
        Route::post('/settings', [AdminSettingsController::class, 'update'])->middleware('admin.can:platform.configure')->name('settings.update');
        // The platform's default for one customization, or its reset.
        Route::post('/customizations/{key}', [AdminCustomizationController::class, 'update'])->middleware('admin.can:platform.configure')->name('customizations.update');

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
        Route::get('/queue/export.csv', [AdminController::class, 'exportQueue'])->middleware('throttle:20,1')->name('queue.export');
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
| Tenant-scoped dashboard for customers to monitor their e-invoicing compliance.
| `portal.tenant` derives the organization from the authenticated session's
| memberships; the controllers never read a tenant identifier from input.
|
*/
Route::prefix('portal')->name('portal.')->middleware('auth')->group(function () {
    // Clears the session selection, so it sits outside `portal.tenant`, which
    // would otherwise immediately re-resolve it.
    Route::get('/switch', [CustomerPortalController::class, 'switchOrganization'])->name('switch');
});

// A person's own preferences in the organization they work in: the views
// they save on its lists. Outside org.writer, because a viewer keeps their
// own as much as anyone, and keeping one changes nothing of the
// organization's.
Route::prefix('portal')->name('portal.')
    ->middleware(['auth', 'auth.session', 'verified', 'portal.tenant', 'org.two_factor', 'throttle:30,1'])
    ->whereUuid(['viewId'])
    ->group(function () {
        Route::post('/views', [SavedListViewController::class, 'portalStore'])->name('views.store');
        Route::post('/views/{viewId}/delete', [SavedListViewController::class, 'portalDestroy'])->name('views.destroy');
        // The branch switcher: one branch, or all of them.
        Route::post('/branch', [PortalWorkingBranchController::class, 'choose'])->name('branch.choose');
        // Following a board item is a person's own: a viewer follows too.
        Route::post('/board/{itemId}/follow', [PortalBoardController::class, 'follow'])->whereUuid('itemId')->name('board.follow');
    });

Route::prefix('portal')->name('portal.')
    // Verified: a signed-up account proves its email address before it sees
    // the portal. Everyone with an account before sign-up existed counts as
    // verified (migration 0370).
    // org.writer: a viewer reads the portal and submits nothing.
    // org.two_factor: an organization that requires a second factor sends
    // anyone without one to set it up first.
    // portal.branch: the branch chosen in the switcher, for the lists, the
    // dashboard and the reports.
    ->middleware(['auth', 'auth.session', 'verified', 'portal.tenant', 'portal.branch', 'org.two_factor', 'org.writer'])
    ->whereUuid(['invoiceId', 'userId', 'invitationId', 'ticketId', 'attachmentId', 'customerId', 'productId', 'paymentId', 'documentId', 'branchId', 'scheduleId'])
    ->group(function () {
        Route::get('/', [CustomerPortalController::class, 'dashboard'])->name('dashboard');
        // Invoices. {invoiceId} rather than {invoice}: route model binding
        // would lean on TenantScope alone, and the scope stands down in
        // console context - PortalInvoiceController names the organization in
        // the query instead, so a foreign id is unfindable rather than merely
        // unscoped.
        Route::get('/invoices', [PortalInvoiceController::class, 'index'])->name('invoices.index');
        // Exactly what the list's filters give, as a CSV. Before {invoiceId},
        // as create is.
        Route::get('/invoices/export.csv', [PortalInvoiceController::class, 'export'])->middleware('throttle:20,1')->name('invoices.export');
        // The credit and debit notes alone, with the same filters.
        Route::get('/notes', [PortalInvoiceController::class, 'notes'])->name('notes.index');
        Route::get('/notes/export.csv', [PortalInvoiceController::class, 'exportNotes'])->middleware('throttle:20,1')->name('notes.export');
        // Notes waiting for a second person, and deciding them
        // (OrganizationApprovals); the service checks who may.
        Route::get('/approvals', [PortalNoteApprovalController::class, 'index'])->name('approvals.index');
        Route::post('/approvals/{requestId}/approve', [PortalNoteApprovalController::class, 'approve'])->whereUuid('requestId')->name('approvals.approve');
        Route::post('/approvals/{requestId}/reject', [PortalNoteApprovalController::class, 'reject'])->whereUuid('requestId')->name('approvals.reject');
        Route::post('/approvals/{requestId}/withdraw', [PortalNoteApprovalController::class, 'withdraw'])->whereUuid('requestId')->name('approvals.withdraw');
        // Before the {invoiceId} route. The group constrains that parameter to
        // a UUID so "create" could not be swallowed as one, but the reading
        // order should not depend on knowing that.
        Route::get('/invoices/create', [PortalInvoiceDraftController::class, 'create'])->name('invoices.create');
        Route::post('/invoices', [PortalInvoiceDraftController::class, 'store'])->name('invoices.store');
        // The same form saved as a sale an insurer pays for: a draft to its
        // recipient with the insurer's share beside it, and the insurer's
        // decision once the invoice is issued.
        Route::post('/invoices/splits', [PortalInsuranceSplitController::class, 'store'])->name('invoices.splits.store');
        Route::post('/invoices/splits/{splitId}/settle', [PortalInsuranceSplitController::class, 'settle'])->whereUuid('splitId')->name('invoices.splits.settle');
        // The form's running figures: each line's VAT and the VAT by category.
        Route::post('/invoices/preview', PortalInvoicePreviewController::class)->middleware('throttle:120,1')->name('invoices.preview');
        Route::get('/invoices/{invoiceId}', [PortalInvoiceController::class, 'show'])->name('invoices.show');
        Route::post('/invoices/{invoiceId}/issue', [PortalInvoiceFilingController::class, 'issue'])->name('invoices.issue');
        // A draft, changed on its form (saved through the store route, which
        // rewrites it) or thrown away. Never an issued invoice.
        Route::get('/invoices/{invoiceId}/edit', [PortalInvoiceDraftController::class, 'edit'])->name('invoices.edit');
        Route::post('/invoices/{invoiceId}/delete', [PortalInvoiceDraftController::class, 'destroy'])->name('invoices.delete');
        // Return, exchange or replace: say what happened, see the documents
        // it makes, then make them (InvoiceAdjustments).
        Route::get('/invoices/{invoiceId}/returns', [PortalInvoiceAdjustmentController::class, 'create'])->name('invoices.adjustments.create');
        Route::post('/invoices/{invoiceId}/returns/preview', [PortalInvoiceAdjustmentController::class, 'preview'])->middleware('throttle:60,1')->name('invoices.adjustments.preview');
        Route::post('/invoices/{invoiceId}/returns', [PortalInvoiceAdjustmentController::class, 'store'])->middleware('throttle:20,1')->name('invoices.adjustments.store');
        // A note correcting an issued invoice, its form filled in from it.
        Route::get('/invoices/{invoiceId}/{note}', [PortalInvoiceDraftController::class, 'adjust'])
            ->whereIn('note', ['credit_note', 'debit_note'])
            ->name('invoices.adjust');
        Route::get('/invoices/{invoiceId}/print', [PortalInvoicePrintController::class, 'print'])->name('invoices.print');
        // The receipt as an image at a till printer's dots.
        Route::get('/invoices/{invoiceId}/receipt.png', [PortalInvoicePrintController::class, 'receiptImage'])->name('invoices.receipt-image');
        // The invoice's QR code as a picture to download.
        Route::get('/invoices/{invoiceId}/qr.{format}', [PortalInvoicePrintController::class, 'qr'])
            ->whereIn('format', ['png', 'svg'])
            ->name('invoices.qr');
        // The PDF the buyer keeps, and sending it to them.
        Route::get('/invoices/{invoiceId}/pdf', [PortalInvoiceDocumentController::class, 'download'])->name('invoices.pdf');
        Route::post('/invoices/{invoiceId}/send', [PortalInvoiceDocumentController::class, 'send'])->middleware('throttle:20,1')->name('invoices.send');

        // The seller details on every invoice, which the country's filing
        // needs complete. Administrators only; the controller checks the role.
        Route::get('/company', [PortalCompanyController::class, 'edit'])->name('company.edit');
        Route::post('/company', [PortalCompanyController::class, 'update'])->name('company.update');
        Route::post('/company/security', [PortalCompanyController::class, 'security'])->name('company.security');
        Route::post('/company/pricing', [PortalCompanyController::class, 'pricing'])->name('company.pricing');
        Route::post('/company/business-type', [PortalCompanyController::class, 'businessType'])->name('company.business-type');

        // Customers and products, which the invoice form fills itself from.
        Route::get('/customers', [PortalCustomerController::class, 'index'])->name('customers.index');
        Route::get('/customers/export.csv', [PortalCustomerController::class, 'export'])->middleware('throttle:20,1')->name('customers.export');
        Route::get('/customers/new', [PortalCustomerController::class, 'create'])->name('customers.create');
        // The invoice form's pickers, searching as someone types.
        Route::get('/customers/lookup', [PortalCatalogueLookupController::class, 'customers'])->middleware('throttle:120,1')->name('customers.lookup');
        Route::get('/products/lookup', [PortalCatalogueLookupController::class, 'products'])->middleware('throttle:120,1')->name('products.lookup');
        Route::post('/customers', [PortalCustomerController::class, 'store'])->name('customers.store');
        Route::get('/customers/{customerId}', [PortalCustomerController::class, 'edit'])->name('customers.edit');
        Route::post('/customers/{customerId}', [PortalCustomerController::class, 'update'])->name('customers.update');
        Route::post('/customers/{customerId}/archive', [PortalCustomerController::class, 'archive'])->name('customers.archive');
        // Removed only while no invoice has used it; brought back from the list.
        Route::post('/customers/{customerId}/remove', [PortalCustomerController::class, 'remove'])->name('customers.remove');
        Route::post('/customers/{customerId}/restore-removed', [PortalCustomerController::class, 'restoreRemoved'])->name('customers.restore-removed');
        Route::get('/products', [PortalProductController::class, 'index'])->name('products.index');
        Route::get('/products/new', [PortalProductController::class, 'create'])->name('products.create');
        Route::post('/products', [PortalProductController::class, 'store'])->name('products.store');
        Route::get('/products/{productId}', [PortalProductController::class, 'edit'])->name('products.edit');
        Route::post('/products/{productId}', [PortalProductController::class, 'update'])->name('products.update');
        Route::post('/products/{productId}/archive', [PortalProductController::class, 'archive'])->name('products.archive');
        Route::post('/products/{productId}/remove', [PortalProductController::class, 'remove'])->name('products.remove');
        Route::post('/products/{productId}/restore-removed', [PortalProductController::class, 'restoreRemoved'])->name('products.restore-removed');

        // Invoices, customers and products from a CSV file: a template per
        // kind, the file checked row by row, and saved only when confirmed -
        // invoices as drafts, never issued.
        Route::get('/imports', [PortalImportController::class, 'index'])->name('imports.index');
        Route::get('/imports/template/{kind}.csv', [PortalImportController::class, 'template'])
            ->whereIn('kind', ['invoices', 'customers', 'products'])->middleware('throttle:30,1')->name('imports.template');
        Route::post('/imports', [PortalImportController::class, 'store'])->middleware('throttle:10,1')->name('imports.store');
        Route::get('/imports/{importId}', [PortalImportController::class, 'show'])->whereUuid('importId')->name('imports.show');
        Route::post('/imports/{importId}/confirm', [PortalImportController::class, 'confirm'])->whereUuid('importId')->middleware('throttle:10,1')->name('imports.confirm');

        // Delivery notes, and a customer's month as one summary invoice,
        // drafted and opened to check and issue.
        Route::get('/summaries', [PortalSummaryInvoiceController::class, 'index'])->name('summaries.index');
        Route::post('/summaries', [PortalSummaryInvoiceController::class, 'store'])->name('summaries.store');
        Route::post('/delivery-notes', [PortalSummaryInvoiceController::class, 'storeNote'])->name('delivery-notes.store');
        Route::post('/delivery-notes/{noteId}/remove', [PortalSummaryInvoiceController::class, 'removeNote'])->whereUuid('noteId')->name('delivery-notes.remove');

        // Contracts billed in stages with retention, and their statements.
        Route::get('/contracts', [PortalContractController::class, 'index'])->name('contracts.index');
        Route::post('/contracts', [PortalContractController::class, 'store'])->name('contracts.store');
        Route::get('/contracts/{contractId}', [PortalContractController::class, 'show'])->whereUuid('contractId')->name('contracts.show');
        Route::post('/contracts/{contractId}/stages/{stageId}/invoice', [PortalContractController::class, 'invoiceStage'])->whereUuid(['contractId', 'stageId'])->name('contracts.stages.invoice');
        Route::post('/contracts/{contractId}/release', [PortalContractController::class, 'release'])->whereUuid('contractId')->name('contracts.release');

        // Schedules: an invoice repeated each period, or an instalment
        // plan. Made from an invoice already written (?invoice=).
        Route::get('/schedules', [PortalScheduleController::class, 'index'])->name('schedules.index');
        Route::get('/schedules/new', [PortalScheduleController::class, 'create'])->name('schedules.create');
        Route::post('/schedules', [PortalScheduleController::class, 'store'])->name('schedules.store');
        Route::get('/schedules/{scheduleId}', [PortalScheduleController::class, 'show'])->name('schedules.show');
        Route::post('/schedules/{scheduleId}/prices', [PortalScheduleController::class, 'prices'])->name('schedules.prices');
        Route::post('/schedules/{scheduleId}/runs/{runId}/paid', [PortalScheduleController::class, 'paid'])->whereUuid('runId')->name('schedules.paid');
        // Administrators only; the controller checks the role.
        Route::post('/schedules/{scheduleId}/runs/{runId}/rerun', [PortalScheduleController::class, 'rerun'])->whereUuid('runId')->name('schedules.rerun');
        foreach (['pause', 'resume', 'cancel', 'skip'] as $action) {
            Route::post('/schedules/{scheduleId}/'.$action, [PortalScheduleController::class, 'act'])->defaults('action', $action)->name('schedules.'.$action);
        }

        // The period's sales as the VAT return groups them, and CSV exports.
        Route::get('/reports', [PortalReportController::class, 'index'])->name('reports');
        // The companies of a group side by side, read-only, each its own taxpayer.
        Route::get('/reports/group', [PortalReportController::class, 'group'])->name('reports.group');
        Route::get('/reports/invoices.csv', [PortalReportController::class, 'invoices'])->middleware('throttle:20,1')->name('reports.invoices');
        Route::get('/reports/vat.csv', [PortalReportController::class, 'vat'])->middleware('throttle:20,1')->name('reports.vat');
        // A day's close by device and branch, where the country counts per device.
        Route::get('/reports/close', [PortalReportController::class, 'close'])->name('reports.close');
        Route::get('/reports/close.csv', [PortalReportController::class, 'closeCsv'])->middleware('throttle:20,1')->name('reports.close.csv');
        // What insurers owe for their shares of split sales, by insurer.
        Route::get('/reports/insurers', [PortalInsuranceSplitController::class, 'receivables'])->name('reports.insurers');

        // The plan, its usage and its payments. Administrators only.
        Route::get('/billing', [PortalBillingController::class, 'show'])->name('billing');
        Route::get('/billing/payments.csv', [PortalBillingController::class, 'export'])->middleware('throttle:20,1')->name('billing.export');
        Route::get('/billing/{paymentId}/invoice', [PortalBillingController::class, 'download'])->name('billing.invoice');
        // The referral link and code, what it brings, and the credit held.
        // Administrators only; the controller checks the role.
        Route::get('/referrals', [PortalReferralController::class, 'show'])->name('referrals');
        // The account with the platform: its statement and its invoices'
        // PDFs. Open whatever the account's standing - a customer always
        // reads and exports its own records.
        Route::get('/billing/statement', [PortalBillingController::class, 'statement'])->name('billing.statement');
        Route::get('/billing/statement.csv', [PortalBillingController::class, 'statementCsv'])->middleware('throttle:20,1')->name('billing.statement.csv');
        Route::get('/billing/invoices/{receivableId}/pdf', [PortalBillingController::class, 'receivablePdf'])->whereUuid('receivableId')->name('billing.receivables.pdf');
        // Paying an invoice by card: off to the card gateway's page, and
        // back. What is recorded is what the gateway says, not the browser.
        Route::post('/billing/invoices/{receivableId}/pay', [PortalBillingController::class, 'pay'])->whereUuid('receivableId')->middleware('throttle:10,1')->name('billing.pay');
        Route::get('/billing/card-return', [PortalBillingController::class, 'cardReturn'])->middleware('throttle:30,1')->name('billing.card-return');

        // Requests - help, a customization or a feature request - as support
        // tickets: everyone in the organization sees its own.
        Route::get('/support', [PortalSupportController::class, 'index'])->name('support.index');
        Route::get('/support/new', [PortalSupportController::class, 'create'])->name('support.create');
        Route::post('/support', [PortalSupportController::class, 'store'])->middleware('throttle:10,1')->name('support.store');
        Route::get('/support/{ticketId}', [PortalSupportController::class, 'show'])->name('support.show');
        Route::post('/support/{ticketId}/reply', [PortalSupportController::class, 'reply'])->middleware('throttle:20,1')->name('support.reply');
        Route::post('/support/{ticketId}/resolve', [PortalSupportController::class, 'markResolved'])->name('support.resolve');
        Route::get('/support/{ticketId}/attachments/{attachmentId}', [PortalSupportController::class, 'attachment'])->name('support.attachment');
        // The feature board: what customers asked for, as an admin published
        // it, with no company named. A company backs what it needs too.
        Route::get('/board', [PortalBoardController::class, 'index'])->name('board.index');
        Route::get('/board/{itemId}', [PortalBoardController::class, 'show'])->whereUuid('itemId')->name('board.show');
        Route::post('/board/{itemId}/back', [PortalBoardController::class, 'back'])->whereUuid('itemId')->middleware('throttle:30,1')->name('board.back');

        // The organization's branches: address, till, numbering, printer,
        // device and who works there. Administrators only; the controller
        // checks the role.
        Route::get('/branches', [PortalBranchController::class, 'index'])->name('branches.index');
        Route::get('/branches/new', [PortalBranchController::class, 'create'])->name('branches.create');
        Route::post('/branches', [PortalBranchController::class, 'store'])->name('branches.store');
        Route::get('/branches/{branchId}', [PortalBranchController::class, 'edit'])->name('branches.edit');
        Route::post('/branches/{branchId}', [PortalBranchController::class, 'update'])->name('branches.update');
        Route::post('/branches/{branchId}/people', [PortalBranchController::class, 'people'])->name('branches.people');

        // Who issued, corrected and changed what, and who invited whom: the
        // organization's activity, from the audit log. Administrators only;
        // the controller checks the role.
        Route::get('/activity', [PortalActivityController::class, 'index'])->name('activity');
        Route::get('/activity/export.csv', [PortalActivityController::class, 'export'])->middleware('throttle:20,1')->name('activity.export');

        // The organization's people, and inviting more. Administrators only;
        // the controller checks the role.
        Route::get('/team', [PortalTeamController::class, 'index'])->name('team.index');
        Route::post('/team/invitations', [PortalTeamController::class, 'invite'])->middleware('throttle:20,1')->name('team.invite');
        Route::post('/team/invitations/{invitationId}/revoke', [PortalTeamController::class, 'revoke'])->name('team.revoke');
        Route::post('/team/members/{userId}/remove', [PortalTeamController::class, 'remove'])->name('team.remove');
        Route::post('/team/members/{userId}/role', [PortalTeamController::class, 'role'])->name('team.role');
        Route::post('/team/members/{userId}/restore', [PortalTeamController::class, 'restore'])->name('team.restore');

        // How documents issued without a number are numbered. Administrators
        // only; the controller checks the role.
        Route::get('/numbering', [PortalNumberingController::class, 'edit'])->name('numbering.edit');
        Route::post('/numbering-mode', [PortalNumberingController::class, 'mode'])->name('numbering.mode');
        Route::post('/numbering/{documentType}', [PortalNumberingController::class, 'update'])->name('numbering.update');

        // Integrations: the organization's own API keys for its ERP or
        // store, made and revoked here. Administrators only; the controller
        // checks the role. Making one is throttled, and limited per hour
        // for the organization as well (ApiKeys).
        Route::get('/integrations', [PortalApiKeyController::class, 'index'])->name('integrations.index');
        Route::post('/integrations/keys', [PortalApiKeyController::class, 'store'])->middleware('throttle:10,1')->name('integrations.keys.store');
        Route::post('/integrations/keys/{keyId}/revoke', [PortalApiKeyController::class, 'revoke'])->whereUuid('keyId')->name('integrations.keys.revoke');
        // Its recent API requests, with no bodies or secrets (RequestLog).
        Route::get('/integrations/requests', [PortalRequestLogController::class, 'index'])->name('integrations.requests');
        // Its webhooks: endpoints, their events and signing secret, a test
        // event, and each one's recent deliveries. A test posts to the
        // customer's own server, so it is throttled like rotating is.
        Route::get('/integrations/webhooks', [PortalWebhookController::class, 'index'])->name('integrations.webhooks.index');
        Route::post('/integrations/webhooks', [PortalWebhookController::class, 'store'])->middleware('throttle:20,1')->name('integrations.webhooks.store');
        Route::get('/integrations/webhooks/{webhookId}', [PortalWebhookController::class, 'show'])->whereUuid('webhookId')->name('integrations.webhooks.show');
        Route::post('/integrations/webhooks/{webhookId}', [PortalWebhookController::class, 'update'])->whereUuid('webhookId')->name('integrations.webhooks.update');
        Route::post('/integrations/webhooks/{webhookId}/delete', [PortalWebhookController::class, 'destroy'])->whereUuid('webhookId')->name('integrations.webhooks.destroy');
        Route::post('/integrations/webhooks/{webhookId}/rotate', [PortalWebhookController::class, 'rotate'])->whereUuid('webhookId')->middleware('throttle:10,1')->name('integrations.webhooks.rotate');
        Route::post('/integrations/webhooks/{webhookId}/test', [PortalWebhookController::class, 'test'])->whereUuid('webhookId')->middleware('throttle:10,1')->name('integrations.webhooks.test');

        // The organization's own value for each customization, against the
        // platform's. Administrators only; the controller checks the role.
        Route::get('/settings', [PortalSettingsController::class, 'edit'])->name('settings.edit');
        Route::post('/settings/{key}', [PortalSettingsController::class, 'update'])->name('settings.update');

        // The paper invoices print on: each person's own, and the
        // organization's and its branches' for its administrators (the
        // controller checks the role).
        Route::get('/printing', [PortalPrintingController::class, 'edit'])->name('printing.edit');
        Route::post('/printing/mine', [PortalPrintingController::class, 'updateMine'])->name('printing.mine');
        Route::post('/printing', [PortalPrintingController::class, 'update'])->name('printing.update');

        // QR codes: the organization's own, and links that open one invoice.
        // Its own codes and what prints on invoices are its administrators'
        // (the controller checks the role).
        Route::get('/qr-codes', [PortalQrCodeController::class, 'index'])->name('qr-codes.index');
        Route::post('/qr-codes', [PortalQrCodeController::class, 'store'])->name('qr-codes.store');
        Route::post('/qr-codes/invoices', [PortalQrCodeController::class, 'updateInvoices'])->name('qr-codes.invoices');
        Route::post('/qr-codes/links', [PortalQrCodeController::class, 'storeLink'])->middleware('throttle:30,1')->name('qr-codes.links.store');
        Route::post('/qr-codes/links/{linkId}/revoke', [PortalQrCodeController::class, 'revokeLink'])->whereUuid('linkId')->name('qr-codes.links.revoke');
        Route::get('/qr-codes/links/{linkId}.{format}', [PortalQrCodeController::class, 'downloadLink'])
            ->whereUuid('linkId')->whereIn('format', ['png', 'svg'])->name('qr-codes.links.download');
        Route::post('/qr-codes/{qrCodeId}/delete', [PortalQrCodeController::class, 'destroy'])->whereUuid('qrCodeId')->name('qr-codes.destroy');
        Route::post('/qr-codes/{qrCodeId}/restore', [PortalQrCodeController::class, 'restore'])->whereUuid('qrCodeId')->name('qr-codes.restore');
        Route::get('/qr-codes/{qrCodeId}.{format}', [PortalQrCodeController::class, 'download'])
            ->whereUuid('qrCodeId')->whereIn('format', ['png', 'svg'])->name('qr-codes.download');

        // The organization's own look over the platform's. Administrators
        // of the organization only; the controller checks the role.
        Route::get('/branding', [PortalBrandingController::class, 'edit'])->name('branding.edit');
        Route::post('/branding', [PortalBrandingController::class, 'update'])->name('branding.update');
        Route::post('/branding/import', [PortalBrandingController::class, 'import'])->name('branding.import');
        Route::get('/branding/export', [PortalBrandingController::class, 'export'])->name('branding.export');
        Route::post('/branding/reset', [PortalBrandingController::class, 'reset'])->name('branding.reset');
        // The organization's invoice template, and a branch's (?branch=).
        // Administrators of the organization only; the controller checks.
        Route::get('/branding/invoice-template', [PortalInvoiceTemplateController::class, 'edit'])->name('invoice-template.edit');
        Route::post('/branding/invoice-template', [PortalInvoiceTemplateController::class, 'update'])->name('invoice-template.update');
        Route::post('/branding/invoice-template/reset', [PortalInvoiceTemplateController::class, 'reset'])->name('invoice-template.reset');
        Route::get('/branding/invoice-template/preview', [PortalInvoiceTemplateController::class, 'preview'])->name('invoice-template.preview');
        Route::get('/branding/invoice-template/sample', [PortalInvoiceTemplateController::class, 'samplePdf'])->middleware('throttle:30,1')->name('invoice-template.sample');
    });

// Books: purchases and suppliers, the VAT return drafted from them and the
// sales, what customers owe and paid, and sales reports. Outside
// org.writer: books (BooksAccess) asks Access for the feature on every
// route, and lets the roles that keep the books - an accountant among
// them - record, while a viewer reads.
Route::prefix('portal/books')->name('portal.books.')
    ->middleware(['auth', 'auth.session', 'verified', 'portal.tenant', 'portal.branch', 'org.two_factor', 'books'])
    ->whereUuid(['purchaseId', 'bookSupplierId', 'saleId', 'paymentId', 'customerId'])
    ->group(function () {
        Route::get('/', [PortalBooksController::class, 'index'])->name('index');

        Route::get('/suppliers', [PortalBookSupplierController::class, 'index'])->name('suppliers.index');
        Route::post('/suppliers', [PortalBookSupplierController::class, 'store'])->name('suppliers.store');
        Route::get('/suppliers/{bookSupplierId}', [PortalBookSupplierController::class, 'edit'])->name('suppliers.edit');
        Route::post('/suppliers/{bookSupplierId}', [PortalBookSupplierController::class, 'update'])->name('suppliers.update');
        Route::post('/suppliers/{bookSupplierId}/remove', [PortalBookSupplierController::class, 'destroy'])->name('suppliers.remove');

        Route::get('/purchases', [PortalPurchaseController::class, 'index'])->name('purchases.index');
        // Where the country gives input VAT back on a purchase unpaid a set time after supply.
        Route::get('/purchases/unpaid', [PortalPurchaseController::class, 'unpaid'])->name('purchases.unpaid');
        Route::get('/purchases/export.csv', [PortalPurchaseController::class, 'export'])->middleware('throttle:20,1')->name('purchases.export');
        Route::get('/purchases/template.csv', [PortalPurchaseController::class, 'template'])->middleware('throttle:30,1')->name('purchases.template');
        Route::post('/purchases/import', [PortalPurchaseController::class, 'import'])->middleware('throttle:10,1')->name('purchases.import');
        Route::get('/purchases/new', [PortalPurchaseController::class, 'create'])->name('purchases.create');
        Route::post('/purchases', [PortalPurchaseController::class, 'store'])->name('purchases.store');
        Route::get('/purchases/{purchaseId}', [PortalPurchaseController::class, 'show'])->name('purchases.show');
        Route::get('/purchases/{purchaseId}/file', [PortalPurchaseController::class, 'file'])->name('purchases.file');
        Route::post('/purchases/{purchaseId}/void', [PortalPurchaseController::class, 'void'])->name('purchases.void');
        // Paying a supplier, where input tax waits on payment, and its notice of a write-off.
        Route::post('/purchases/{purchaseId}/payments', [PortalPurchaseController::class, 'pay'])->middleware('throttle:30,1')->name('purchases.pay');
        Route::post('/purchase-payments/{paymentId}/reverse', [PortalPurchaseController::class, 'reversePayment'])->name('purchases.payments.reverse');
        Route::post('/purchases/{purchaseId}/write-off', [PortalPurchaseController::class, 'writeOff'])->name('purchases.write-off');

        Route::get('/vat-return', [PortalVatReturnController::class, 'show'])->name('vat-return');
        Route::get('/vat-return.csv', [PortalVatReturnController::class, 'csv'])->middleware('throttle:20,1')->name('vat-return.csv');
        Route::get('/vat-return.pdf', [PortalVatReturnController::class, 'pdf'])->middleware('throttle:20,1')->name('vat-return.pdf');
        // Marking the drafted period filed, and an owner reopening one.
        Route::post('/vat-return/file', [PortalVatReturnController::class, 'file'])->name('vat-return.file');
        Route::post('/vat-periods/{periodId}/reopen', [PortalVatReturnController::class, 'reopen'])->whereUuid('periodId')->name('vat-periods.reopen');
        Route::get('/vat-return/boxes/{box}', [PortalVatReturnController::class, 'box'])->where('box', '[0-9a-z-]{1,16}')->name('vat-return.box');

        Route::get('/receivables', [PortalReceivablesController::class, 'index'])->name('receivables');
        Route::get('/receivables.csv', [PortalReceivablesController::class, 'export'])->middleware('throttle:20,1')->name('receivables.csv');
        Route::get('/invoices/{saleId}', [PortalReceivablesController::class, 'invoice'])->name('invoices.show');
        Route::post('/invoices/{saleId}/payments', [PortalReceivablesController::class, 'pay'])->middleware('throttle:30,1')->name('invoices.pay');
        // Bad-debt relief on an unpaid invoice.
        Route::post('/invoices/{saleId}/bad-debt', [PortalReceivablesController::class, 'badDebt'])->name('invoices.bad-debt');
        Route::post('/payments/{paymentId}/reverse', [PortalReceivablesController::class, 'reverse'])->name('payments.reverse');
        Route::get('/statements/{customerId}', [PortalReceivablesController::class, 'statement'])->name('statements.show');
        Route::get('/statements/{customerId}/pdf', [PortalReceivablesController::class, 'statementPdf'])->middleware('throttle:20,1')->name('statements.pdf');

        // Vouchers: sold with no invoice, used to pay an issued one.
        Route::get('/vouchers', [PortalVoucherController::class, 'index'])->name('vouchers');
        Route::post('/vouchers', [PortalVoucherController::class, 'store'])->name('vouchers.store');
        Route::post('/vouchers/redeem', [PortalVoucherController::class, 'redeem'])->middleware('throttle:30,1')->name('vouchers.redeem');

        Route::get('/sales', [PortalSalesReportController::class, 'index'])->name('sales');
        Route::get('/sales.csv', [PortalSalesReportController::class, 'export'])->middleware('throttle:20,1')->name('sales.csv');
    });

// A person's own preferences. Outside org.writer: a viewer chooses their own
// paper and language too, and nothing here changes the organization's.
Route::prefix('portal')->name('portal.')
    ->middleware(['auth', 'auth.session', 'verified', 'portal.tenant', 'org.two_factor'])
    ->group(function () {
        Route::get('/preferences', [PortalPreferencesController::class, 'edit'])->name('preferences.edit');
        Route::post('/preferences/{key}', [PortalPreferencesController::class, 'update'])->name('preferences.update');
    });

/*
|--------------------------------------------------------------------------
| Any other address
|--------------------------------------------------------------------------
|
| An address no route matches is a 404 either way. Raised by the router it
| is drawn without the web middleware, so the page cannot tell a signed-in
| person from a visitor and offers them only the public home page. Raised
| here, inside the web group, the session is read and the 404 leads back to
| their own dashboard (errors/partials/message: bearings).
|
| Not for the API's addresses (api/..., v1/...): they keep the router's 404
| and its JSON envelope, and start no session. GET only, as every fallback.
|
| And the web group only when the request already has a session
| (WebOnlyWithSession): a crawler or a first visit gets the visitor's 404
| with no cookie set and no session written.
|
*/
Route::fallback(fn () => abort(404))
    ->withoutMiddleware('web')
    ->middleware(WebOnlyWithSession::class)
    ->where('fallbackPlaceholder', '(?!api(?:/|$)|v[0-9]+(?:/|$)).*');
