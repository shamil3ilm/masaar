<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Http\Controllers\AdminEinvoicingStartController;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Http\Controllers\PortalEinvoicingStartController;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Http\Controllers\PortalProviderAppointmentController;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Http\Controllers\PortalReceivedController;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Http\Controllers\PortalUaeInvoiceController;
use App\Domains\Compliance\Engine\Services\JurisdictionRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * The United Arab Emirates' registration with the compliance engine.
 *
 * Registered whether or not the UAE is on; the module answers enabled() from
 * the admin console's switch, else PEPPOL_AE_ENABLED, as every module
 * does, and the engine refuses a switched-off country.
 *
 * Its documents travel over the shared Peppol network, whose gateway
 * Networks\Peppol\NetworkServiceProvider binds.
 */
class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(JurisdictionRegistry::class, function (JurisdictionRegistry $registry): void {
            $registry->register('AE', fn (): Module => $this->app->make(Module::class));
        });
    }

    /**
     * The module's portal screens, behind the portal's own middleware: the
     * UAE invoice form's draft, the e-invoice an issued invoice was sent as,
     * the invoices other suppliers sent, and the appointed service provider.
     * A UAE organization's only; anyone else gets a 404 (UaePortal).
     * Registered here rather than in routes/web.php, which only loses
     * country knowledge (ModuleBoundaryTest).
     */
    public function boot(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        Route::middleware(['web', 'auth', 'auth.session', 'verified', 'portal.tenant', 'portal.branch', 'org.two_factor', 'org.writer'])
            ->prefix('portal')
            ->name('portal.')
            ->whereUuid(['invoiceId', 'documentId'])
            ->group(function (): void {
                Route::post('/invoices/uae', [PortalUaeInvoiceController::class, 'store'])->name('invoices.uae.store');
                Route::get('/invoices/{invoiceId}/e-invoice', [PortalUaeInvoiceController::class, 'xml'])->name('invoices.xml');
                Route::get('/received', [PortalReceivedController::class, 'index'])->name('received.index');
                Route::get('/received/{documentId}', [PortalReceivedController::class, 'show'])->name('received.show');
                Route::get('/received/{documentId}/xml', [PortalReceivedController::class, 'xml'])->name('received.xml');
                // The organization's appointed e-invoicing service provider.
                Route::post('/company/service-provider', [PortalProviderAppointmentController::class, 'update'])->name('company.service-provider');
                // The date its e-invoicing starts (EinvoicingStart).
                Route::post('/company/einvoicing-start', [PortalEinvoicingStartController::class, 'update'])->name('company.einvoicing-start');
            });

        // A platform admin reads and sets an organization's e-invoicing start
        // date, as its administrators can on its company page.
        Route::middleware(['web', 'auth', 'auth.session', 'platform.admin', 'admin.can:console.view', 'admin.audit'])
            ->prefix('admin')
            ->name('admin.')
            ->whereUuid('id')
            ->group(function (): void {
                Route::get('/organizations/{id}/einvoicing-start', [AdminEinvoicingStartController::class, 'show'])->name('organizations.einvoicing-start');
                Route::post('/organizations/{id}/einvoicing-start', [AdminEinvoicingStartController::class, 'update'])
                    ->middleware('admin.can:features.manage')
                    ->name('organizations.einvoicing-start.update');
            });
    }
}
