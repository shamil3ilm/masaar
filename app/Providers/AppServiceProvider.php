<?php

namespace App\Providers;

use App\Domains\Auth\Contracts\AuthenticatesUsers;
use App\Domains\Auth\Services\JwtAuthenticatesUsers;
use App\Domains\Compliance\Fatoora\Services\TimestampValidator;
use App\Domains\Compliance\Fatoora\Services\WebhookPayloadBuilder;
use App\Domains\Compliance\Fatoora\Services\EnvironmentVarianceTracker;
use App\Domains\Compliance\Fatoora\Services\CertificateLineageService;
use App\Domains\Compliance\Fatoora\Services\AtomicIcvManager;
use App\Domains\Compliance\Fatoora\Services\ClusterCircuitBreaker;
use App\Domains\Compliance\Fatoora\Services\KeyCompromiseHandler;
use App\Domains\Compliance\Fatoora\Services\QueueHealthMonitor;
use App\Domains\Compliance\Fatoora\Services\FallbackHandler;
use App\Domains\Logging\Services\ComplianceLogger;
use App\Domains\Organization\Services\TenantResolver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Auth: JWT implementation
        $this->app->bind(
            AuthenticatesUsers::class,
            JwtAuthenticatesUsers::class
        );

        // Tenant: Singleton for request-scoped context
        $this->app->singleton(TenantResolver::class);

        // ZATCA Compliance Services - Singletons for consistency
        $this->app->singleton(TimestampValidator::class);
        $this->app->singleton(WebhookPayloadBuilder::class);
        $this->app->singleton(EnvironmentVarianceTracker::class);
        $this->app->singleton(CertificateLineageService::class);
        $this->app->singleton(AtomicIcvManager::class);
        $this->app->singleton(ClusterCircuitBreaker::class);
        $this->app->singleton(KeyCompromiseHandler::class);
        $this->app->singleton(QueueHealthMonitor::class);
        $this->app->singleton(FallbackHandler::class);
        $this->app->singleton(ComplianceLogger::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
