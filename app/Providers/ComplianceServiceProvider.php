<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Compliance\ComplianceRouter;
use App\Domains\Compliance\Fatoora\FatooraEngine;
use Illuminate\Support\ServiceProvider;

class ComplianceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Tag all engine implementations for collective resolution
        $this->app->tag([FatooraEngine::class], 'compliance.engines');

        // Bind ComplianceRouter with all tagged engines
        $this->app->singleton(ComplianceRouter::class, function ($app) {
            return new ComplianceRouter(
                engines: iterator_to_array($app->tagged('compliance.engines')),
            );
        });
    }
}
