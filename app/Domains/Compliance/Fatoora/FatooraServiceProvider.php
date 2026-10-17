<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora;

use App\Domains\Compliance\Engine\Services\JurisdictionRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Saudi Arabia's registration with the compliance engine.
 *
 * The default jurisdiction: an organization with no country is a row from
 * before the column was set, and those were Saudi.
 */
class FatooraServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(JurisdictionRegistry::class, function (JurisdictionRegistry $registry): void {
            $registry->register('SA', fn (): SaudiArabia => $this->app->make(SaudiArabia::class), default: true);
        });
    }
}
