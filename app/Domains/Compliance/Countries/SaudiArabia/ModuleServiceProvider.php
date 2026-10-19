<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers\AdminCertificateController;
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
     * The module's own console page: every device certificate, and marking
     * one revoked. Registered here rather than in routes/web.php, which only
     * loses country knowledge (ModuleBoundaryTest), behind the console's own
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
    }
}
