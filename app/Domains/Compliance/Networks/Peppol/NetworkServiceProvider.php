<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol;

use App\Domains\Compliance\Networks\Peppol\Contracts\AspGateway;
use App\Domains\Compliance\Networks\Peppol\Gateways\HttpAspGateway;
use App\Domains\Compliance\Networks\Peppol\Gateways\NullAspGateway;
use App\Domains\Compliance\Networks\Peppol\Gateways\WebhookSignature;
use App\Domains\Compliance\Networks\Peppol\Http\Controllers\AdminPeppolController;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use App\Domains\Compliance\Networks\Peppol\Sending\DocumentHistory;
use App\Domains\Compliance\Networks\Peppol\Simulator\SimulatedAspGateway;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Peppol: which service provider the platform talks to, for every country
 * that sends through the network. Each such country registers its own
 * module, from its own ModuleServiceProvider.
 *
 * The transport is decided by configuration alone. No base URL is a
 * deployment without a provider, where documents wait for a manual upload;
 * production refuses a base URL without its credentials (AppServiceProvider),
 * so the HTTP gateway is never built half-configured there.
 *
 * PEPPOL_GATEWAY=simulator replaces the provider with SimulatedAspGateway,
 * for local and staging. Production refuses it twice: at boot, so a
 * deployment configured that way never serves, and here when it is built,
 * so changing the configuration later cannot slip it in either.
 *
 * Bound per resolution rather than once, so the answer follows the
 * configuration a worker or test is running with.
 */
class NetworkServiceProvider extends ServiceProvider
{
    public const SIMULATOR = 'simulator';

    public function register(): void
    {
        $this->app->bind(AspGateway::class, function (Application $app): AspGateway {
            $gateway = config('peppol.gateway');

            if ($gateway === self::SIMULATOR) {
                self::refuseSimulatorIn($app);

                return $app->make(SimulatedAspGateway::class);
            }

            // A misspelt choice must not quietly become another gateway.
            if ($gateway !== null && $gateway !== '') {
                throw new RuntimeException('PEPPOL_GATEWAY is '.var_export($gateway, true).', which is not a gateway: leave it empty for the provider at PEPPOL_ASP_BASE_URL, or set it to simulator.');
            }

            $baseUrl = config('peppol.asp.base_url');

            if (! is_string($baseUrl) || $baseUrl === '') {
                return new NullAspGateway;
            }

            return new HttpAspGateway(
                baseUrl: $baseUrl,
                clientId: config('peppol.asp.client_id'),
                clientSecret: config('peppol.asp.client_secret'),
                signature: new WebhookSignature(config('peppol.asp.webhook_secret')),
            );
        });
    }

    public function boot(): void
    {
        if (config('peppol.gateway') === self::SIMULATOR) {
            self::refuseSimulatorIn($this->app);
        }

        // A document's record starts with the document: its first state is
        // its first event, so the record alone adds up to where it stands.
        PeppolDocument::created(function (PeppolDocument $document): void {
            $this->app->make(DocumentHistory::class)->created(
                $document,
                $document->direction === PeppolDocument::DIRECTION_IN ? DocumentHistory::SOURCE_CALLBACK : DocumentHistory::SOURCE_SYSTEM,
            );
        });

        if ($this->app->routesAreCached()) {
            return;
        }

        // The network's console page: the gateway, documents per state,
        // Resend for a failed one, and Retry report for one whose report to
        // the authority failed. Behind the console's own middleware, as
        // routes/web.php's admin group is; a country that sends through the
        // network links it from the console (Presentation::NAV_CONSOLE).
        Route::middleware(['web', 'auth', 'auth.session', 'platform.admin', 'admin.can:console.view', 'admin.audit'])
            ->prefix('admin')
            ->name('admin.')
            ->whereUuid(['id'])
            ->group(function (): void {
                Route::get('/peppol', [AdminPeppolController::class, 'index'])->name('peppol');
                Route::post('/peppol/{id}/resend', [AdminPeppolController::class, 'resend'])->middleware('admin.can:operations.run')->name('peppol.resend');
                Route::post('/peppol/{id}/retry-report', [AdminPeppolController::class, 'retryReport'])->middleware('admin.can:operations.run')->name('peppol.retry-report');
            });
    }

    /**
     * The simulator files nothing with anybody while answering as if it had,
     * which no later check could tell from the real thing.
     */
    public static function refuseSimulatorIn(Application $app): void
    {
        if ($app->environment('production')) {
            throw new RuntimeException(
                'PEPPOL_GATEWAY=simulator must not be used when APP_ENV=production. '
                .'The simulator answers for a service provider and sends nothing to the network: '
                .'every invoice would look delivered and none would be.'
            );
        }
    }
}
