<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers\AdminAlertController;
use App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers\AdminArchiveController;
use App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers\AdminCertificateController;
use App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers\AdminEnvironmentController;
use App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers\PortalArchiveController;
use App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers\PortalCertificateRenewalController;
use App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers\PortalSubmissionController;
use App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers\PortalTillController;
use App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers\PortalZatcaController;
use App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers\SubmissionResendController;
use App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers\UnitController;
use App\Domains\Compliance\Countries\SaudiArabia\Models\InvoiceSubmission;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\EnvironmentSwitchActions;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CertificateRevocationActions;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\GovernedRevocationApprovals;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\RevocationApprovals;
use App\Domains\Compliance\Engine\Services\JurisdictionRegistry;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Platform\Approvals\ApprovalCatalogue;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Saudi Arabia's registration with the compliance engine.
 *
 * The default jurisdiction: an organization with no country is a row from
 * before the column was set, and those were Saudi.
 *
 * Registered whether or not Saudi Arabia is on; the module answers enabled()
 * from the admin console's switch, else ZATCA_ENABLED, as every module
 * does, and the engine refuses a switched-off country.
 */
class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(JurisdictionRegistry::class, function (JurisdictionRegistry $registry): void {
            $registry->register('SA', fn (): Module => $this->app->make(Module::class), default: true);
        });

        // A manual revocation is an admin action the platform's approvals
        // hold, on as shipped: it stops a till signing. So is a switch to or
        // from production: it moves a taxpayer onto the live gateway, or off.
        $this->app->afterResolving(ApprovalCatalogue::class, function (ApprovalCatalogue $catalogue): void {
            $catalogue->extend(CertificateRevocationActions::REVOKE, CertificateRevocationActions::class, heldByDefault: true);
            $catalogue->extend(EnvironmentSwitchActions::PRODUCTION, EnvironmentSwitchActions::class, heldByDefault: true);
        });
        $this->app->bindIf(RevocationApprovals::class, GovernedRevocationApprovals::class);

        // An invoice's submissions to ZATCA, and the latest of them, which
        // Invoice::latestFiling() reads through FilingRecord: the relations
        // are this module's, so the Invoice model names no country's model.
        Invoice::resolveRelationUsing('submissions', fn (Invoice $invoice): HasMany => $invoice->hasMany(InvoiceSubmission::class));
        Invoice::resolveRelationUsing('latestSubmission', fn (Invoice $invoice): HasOne => $invoice->hasOne(InvoiceSubmission::class)->latestOfMany());
    }

    /**
     * The module's own console pages - every device certificate, marking one
     * revoked, and the submissions past their deadline - and its portal
     * screens: connecting to ZATCA, the certificates, late reporting, the
     * submissions and the certificate renewal. Registered here rather than in
     * routes/web.php, which only loses country knowledge (ModuleBoundaryTest).
     * The console pages sit behind the console's own middleware -
     * platform.admin, the console permission, the audit of every change -
     * with configuring the platform required to revoke.
     */
    public function boot(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        Route::middleware(['web', 'auth', 'auth.session', 'platform.admin', 'admin.can:console.view', 'admin.audit'])
            ->prefix('admin')
            ->name('admin.')
            ->group(function (): void {
                Route::get('/certificates', [AdminCertificateController::class, 'index'])->name('certificates');
                // A failed submission, sent again by hand.
                Route::post('/submissions/{submissionId}/retry', [SubmissionResendController::class, 'console'])
                    ->whereUuid('submissionId')
                    ->middleware(['admin.can:operations.run', 'throttle:30,1'])
                    ->name('submissions.retry');
                // Submissions past an age without having reached ZATCA.
                Route::get('/alerts', [AdminAlertController::class, 'index'])->name('alerts');
                Route::post('/certificates/{version}/revoke', [AdminCertificateController::class, 'revoke'])
                    ->whereNumber('version')
                    ->middleware('admin.can:platform.configure')
                    ->name('certificates.revoke');
            });

        // A platform admin sets which ZATCA environment an organization
        // works in (FatooraEnvironment); a switch archives its credentials.
        Route::middleware(['web', 'auth', 'auth.session', 'platform.admin', 'admin.can:console.view', 'admin.audit'])
            ->prefix('admin')
            ->name('admin.')
            ->whereUuid('id')
            ->group(function (): void {
                Route::get('/organizations/{id}/zatca-environment', [AdminEnvironmentController::class, 'show'])->name('organizations.zatca-environment');
                Route::post('/organizations/{id}/zatca-environment', [AdminEnvironmentController::class, 'update'])
                    ->middleware('admin.can:platform.configure')
                    ->name('organizations.zatca-environment.update');
                // The organization's e-invoices for a period as one ZIP
                // (DocumentArchive), on its activity record.
                Route::get('/organizations/{id}/zatca-archive', [AdminArchiveController::class, 'download'])
                    ->middleware(['admin.can:operations.run', 'throttle:6,1'])
                    ->name('organizations.zatca-archive');
            });

        // The portal's ZATCA screens, behind the portal's own middleware.
        Route::middleware(['web', 'auth', 'auth.session', 'verified', 'portal.tenant', 'portal.branch', 'org.two_factor', 'org.writer'])
            ->prefix('portal')
            ->name('portal.')
            ->whereUuid(['userId'])
            ->group(function (): void {
                // The submissions, filterable by person, and each person's.
                Route::get('/submissions', [PortalSubmissionController::class, 'submissions'])->name('submissions');
                Route::get('/submissions/export.csv', [PortalSubmissionController::class, 'export'])->middleware('throttle:20,1')->name('submissions.export');
                // A failed submission, sent again by an administrator.
                Route::post('/submissions/{submissionId}/retry', [SubmissionResendController::class, 'portal'])
                    ->whereUuid('submissionId')
                    ->middleware('throttle:30,1')
                    ->name('submissions.retry');
                Route::get('/certificates', [PortalSubmissionController::class, 'certificates'])->name('certificates');
                Route::get('/users/{userId}/activity', [PortalSubmissionController::class, 'userActivity'])->name('user.activity');

                // Connecting to ZATCA: OTP, compliance checks, production
                // certificate. Administrators only, and only once the company is
                // approved; the controller checks both. Each step reaches ZATCA, so
                // they are throttled.
                Route::get('/zatca', [PortalZatcaController::class, 'show'])->name('zatca.show');
                Route::post('/zatca/ccsid', [PortalZatcaController::class, 'ccsid'])->middleware('throttle:6,1')->name('zatca.ccsid');
                Route::post('/zatca/check', [PortalZatcaController::class, 'check'])->middleware('throttle:6,1')->name('zatca.check');
                Route::post('/zatca/production', [PortalZatcaController::class, 'production'])->middleware('throttle:6,1')->name('zatca.production');
                // Each device's certificates, and what still waits to be filed under
                // each. Administrators only; the controller checks.
                Route::get('/zatca/certificates', [PortalZatcaController::class, 'certificates'])->name('zatca.certificates');
                // Invoices not reported within 24 hours, and the notices ZATCA's
                // guidelines ask for. Administrators only; the controller checks.
                Route::get('/zatca/late', [PortalZatcaController::class, 'late'])->name('zatca.late');
                Route::post('/zatca/late/notified', [PortalZatcaController::class, 'lateNotified'])->name('zatca.late.notified');
                Route::get('/zatca/late/evidence.csv', [PortalZatcaController::class, 'lateEvidence'])->middleware('throttle:20,1')->name('zatca.late.evidence');
                // A period's e-invoices as one ZIP for the organization's own
                // archive: owners, administrators and the accountant; the
                // controller checks.
                Route::get('/zatca/archive', [PortalArchiveController::class, 'download'])->middleware('throttle:6,1')->name('zatca.archive');
            });

        // Renewing the organization's certificate, or a till's, from the
        // portal's Certificates panel, behind the portal's own middleware. Each step
        // reaches ZATCA, so the two that send are throttled; the controller
        // checks the administrator and the clearance.
        Route::middleware(['web', 'auth', 'auth.session', 'verified', 'portal.tenant', 'portal.branch', 'org.two_factor', 'org.writer'])
            ->prefix('portal')
            ->name('portal.')
            ->group(function (): void {
                Route::get('/zatca/certificates/renew', [PortalCertificateRenewalController::class, 'show'])->name('zatca.renew');
                Route::post('/zatca/certificates/renew', [PortalCertificateRenewalController::class, 'renew'])->middleware('throttle:6,1')->name('zatca.renew.store');
                Route::post('/zatca/certificates/renew/finish', [PortalCertificateRenewalController::class, 'finish'])->middleware('throttle:6,1')->name('zatca.renew.finish');

                // A branch's tills: adding one from the branch page, and
                // retiring one once nothing it signed is left to file.
                Route::post('/branches/{branchId}/tills', [PortalTillController::class, 'store'])->name('branches.tills.store');
                Route::post('/zatca/tills/{unitId}/retire', [PortalTillController::class, 'retire'])->name('zatca.tills.retire');
                Route::post('/zatca/tills/{unitId}/printing', [PortalTillController::class, 'printing'])->name('zatca.tills.printing');

                // One till's certificate: connecting it with its own OTP, or
                // renewing it through the same guarded steps as head office's.
                Route::get('/zatca/tills/{unitId}/certificate', [PortalCertificateRenewalController::class, 'showTill'])->name('zatca.tills.certificate');
                Route::post('/zatca/tills/{unitId}/certificate', [PortalCertificateRenewalController::class, 'renewTill'])->middleware('throttle:6,1')->name('zatca.tills.certificate.renew');
                Route::post('/zatca/tills/{unitId}/certificate/finish', [PortalCertificateRenewalController::class, 'finishTill'])->middleware('throttle:6,1')->name('zatca.tills.certificate.finish');
            });

        // The organization's tills (EGS units) over the tenant API, behind
        // that surface's own guard (routes/api/tenant.php): listing them for
        // anyone who writes, adding one and onboarding it - each with its own
        // OTP - for its administrators, onboarding only once it may file.
        Route::middleware(['api', 'jwt.auth', 'rate.api', 'org.writer'])
            ->prefix('api/organizations')
            ->group(function (): void {
                Route::get('/units', [UnitController::class, 'index']);

                Route::middleware('org.admin')->group(function (): void {
                    Route::post('/branches/{branch}/units', [UnitController::class, 'store']);
                    Route::post('/units/{unit}/retire', [UnitController::class, 'retire']);

                    Route::middleware('filing.cleared')->group(function (): void {
                        Route::post('/units/{unit}/onboarding/ccsid', [UnitController::class, 'ccsid']);
                        Route::post('/units/{unit}/onboarding/compliance-check', [UnitController::class, 'complianceCheck']);
                        Route::post('/units/{unit}/onboarding/pcsid', [UnitController::class, 'pcsid']);
                    });
                });
            });
    }
}
