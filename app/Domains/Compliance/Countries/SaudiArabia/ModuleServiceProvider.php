<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers\AdminCertificateController;
use App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers\PortalCertificateRenewalController;
use App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers\PortalTillController;
use App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers\UnitController;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CertificateRevocationActions;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\GovernedRevocationApprovals;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\RevocationApprovals;
use App\Domains\Compliance\Engine\Services\JurisdictionRegistry;
use App\Domains\Platform\Approvals\ApprovalCatalogue;
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
        // hold, on as shipped: it stops a till signing.
        $this->app->afterResolving(ApprovalCatalogue::class, function (ApprovalCatalogue $catalogue): void {
            $catalogue->extend(CertificateRevocationActions::REVOKE, CertificateRevocationActions::class, heldByDefault: true);
        });
        $this->app->bindIf(RevocationApprovals::class, GovernedRevocationApprovals::class);
    }

    /**
     * The module's own console page - every device certificate, and marking
     * one revoked - and the portal's certificate renewal. Registered here
     * rather than in routes/web.php, which only loses country knowledge
     * (ModuleBoundaryTest). The console page sits behind the console's own
     * middleware - platform.admin, the console permission, the audit of every
     * change - with configuring the platform required to revoke.
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
                Route::post('/certificates/{version}/revoke', [AdminCertificateController::class, 'revoke'])
                    ->whereNumber('version')
                    ->middleware('admin.can:platform.configure')
                    ->name('certificates.revoke');
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
