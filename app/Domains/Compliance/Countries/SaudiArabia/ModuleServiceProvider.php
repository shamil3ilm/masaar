<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers\AdminCertificateController;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\ImmediateRevocationApprovals;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\RevocationApprovals;
use App\Domains\Compliance\Engine\Services\JurisdictionRegistry;
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

        // The approval-policy hook for a manual revocation. No approval
        // policies exist yet, so every request takes effect at once; a
        // policy binds its own RevocationApprovals here.
        $this->app->bindIf(RevocationApprovals::class, ImmediateRevocationApprovals::class);
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
