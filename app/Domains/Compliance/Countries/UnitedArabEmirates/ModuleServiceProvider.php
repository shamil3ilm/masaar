<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates;

use App\Domains\Compliance\Engine\Services\JurisdictionRegistry;
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
}
