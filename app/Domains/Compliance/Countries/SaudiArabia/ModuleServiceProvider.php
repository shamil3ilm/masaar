<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia;

use App\Domains\Compliance\Engine\Services\JurisdictionRegistry;
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
    }
}
