<?php

declare(strict_types=1);

namespace App\Domains\Billing;

use App\Domains\Billing\Contracts\PaymentGateway;
use App\Domains\Billing\Gateways\SimulatedPaymentGateway;
use App\Domains\Billing\Gateways\UnavailablePaymentGateway;
use App\Domains\Billing\Http\Controllers\PaymentSimulatorController;
use App\Domains\Billing\Services\Freeze;
use App\Domains\Platform\Access\AccountStanding;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * The platform billing its customers: which card gateway takes card
 * payments, and where each customer's account stands for Access.
 *
 * The gateway is decided by configuration alone (billing.gateway): none
 * yet, or the simulator. PAYMENT_GATEWAY=simulator is for local and
 * staging; production refuses it twice, as it refuses the e-invoicing
 * network's simulator (NetworkServiceProvider): at boot, so a deployment
 * configured that way never serves, and when the gateway is built, so a
 * configuration changed later cannot slip it in. Bound per resolution, so
 * the answer follows the configuration a worker or test runs with.
 */
class BillingServiceProvider extends ServiceProvider
{
    public const SIMULATOR = 'simulator';

    public function register(): void
    {
        // Where a customer's account stands, for Access: the
        // overdue-payment freeze.
        $this->app->bind(AccountStanding::class, Freeze::class);

        $this->app->bind(PaymentGateway::class, function (Application $app): PaymentGateway {
            $gateway = config('billing.gateway');

            if ($gateway === self::SIMULATOR) {
                self::refuseSimulatorIn($app);

                return $app->make(SimulatedPaymentGateway::class);
            }

            // A misspelt choice must not quietly become no gateway at all.
            if ($gateway !== null && $gateway !== '') {
                throw new RuntimeException('PAYMENT_GATEWAY is '.var_export($gateway, true).', which is not a card gateway: leave it empty for none, or set it to simulator.');
            }

            return new UnavailablePaymentGateway;
        });
    }

    public function boot(): void
    {
        if (config('billing.gateway') === self::SIMULATOR) {
            self::refuseSimulatorIn($this->app);
        }

        if ($this->app->environment('production') || $this->app->routesAreCached()) {
            return;
        }

        // The simulator's "provider page": where a card payment is approved
        // or declined by hand, then the customer goes back where they came
        // from. Never registered in production; it answers only while the
        // simulator is the gateway (PaymentSimulatorController).
        Route::middleware(['web', 'auth'])
            ->prefix('billing/simulator')
            ->name('billing.simulator.')
            ->where(['sessionId' => 'sim_[a-z0-9]{26}'])
            ->group(function (): void {
                Route::get('/{sessionId}', [PaymentSimulatorController::class, 'show'])->name('show');
                Route::post('/{sessionId}', [PaymentSimulatorController::class, 'decide'])->middleware('throttle:30,1')->name('decide');
            });
    }

    /**
     * The simulator takes no money while saying it did, which no later check
     * could tell from the real thing.
     */
    public static function refuseSimulatorIn(Application $app): void
    {
        if ($app->environment('production')) {
            throw new RuntimeException(
                'PAYMENT_GATEWAY=simulator must not be used when APP_ENV=production. '
                .'The simulator approves card payments without taking any money: '
                .'every invoice paid by card would look paid and none would be.'
            );
        }
    }
}
