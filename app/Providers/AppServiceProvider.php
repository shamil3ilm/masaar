<?php

namespace App\Providers;

use App\Domains\Auth\Contracts\AuthenticatesUsers;
use App\Domains\Auth\Services\JwtAuthenticatesUsers;
use App\Domains\Compliance\Fatoora\Services\CertificateLineageService;
use App\Domains\Compliance\Fatoora\Services\ClusterCircuitBreaker;
use App\Domains\Compliance\Fatoora\Services\EnvironmentVarianceTracker;
use App\Domains\Compliance\Fatoora\Services\FallbackHandler;
use App\Domains\Compliance\Fatoora\Services\TimestampValidator;
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
        $this->app->singleton(EnvironmentVarianceTracker::class);
        $this->app->singleton(CertificateLineageService::class);
        $this->app->singleton(ClusterCircuitBreaker::class);
        $this->app->singleton(FallbackHandler::class);
        $this->app->singleton(ComplianceLogger::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->assertDebugDisabledInProduction();
    }

    /**
     * Refuse to run a production deployment with debug mode enabled.
     *
     * With APP_DEBUG on, the catch-all API exception renderer returns
     * $e->getMessage() to the caller, disclosing stack-trace-grade internals.
     * Failing at boot is deliberate: a misconfigured deployment should be
     * obviously broken rather than quietly leaking.
     */
    private function assertDebugDisabledInProduction(): void
    {
        if ($this->app->environment('production') && config('app.debug') === true) {
            throw new \RuntimeException(
                'APP_DEBUG must be false when APP_ENV=production. '
                .'Debug mode exposes internal error detail through the API.'
            );
        }
    }
}
