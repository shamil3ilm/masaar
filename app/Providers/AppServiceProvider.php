<?php

namespace App\Providers;

use App\Domains\Auth\Contracts\Authenticator;
use App\Domains\Auth\Services\JwtAuthenticator;
use App\Domains\Compliance\Fatoora\Services\CertificateLineageService;
use App\Domains\Compliance\Fatoora\Services\ClusterCircuitBreaker;
use App\Domains\Compliance\Fatoora\Services\EnvironmentVarianceTracker;
use App\Domains\Compliance\Fatoora\Services\FallbackHandler;
use App\Domains\Compliance\Fatoora\Services\TimestampValidator;
use App\Domains\Logging\Services\ComplianceLogger;
use App\Domains\Organization\Services\TenantResolver;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * The application's route middleware aliases.
     *
     * Declared here rather than inline in bootstrap/app.php so there is one
     * list, and so boot() can reassert it — see reclaimMiddlewareAliases().
     */
    public const MIDDLEWARE_ALIASES = [
        'jwt.auth' => \App\Domains\Auth\Http\Middleware\JwtGuard::class,
        'platform.admin' => \App\Domains\Auth\Http\Middleware\IsPlatformAdmin::class,
        'api.key' => \App\Domains\Auth\Http\Middleware\ApiKeyAuth::class,
        'portal.tenant' => \App\Domains\Organization\Http\Middleware\PortalTenant::class,
        'metrics' => \App\Domains\Platform\Http\Middleware\MetricsAccess::class,
        'rate.api' => \App\Domains\Platform\Http\Middleware\RateLimitApi::class,
        'license' => \App\Domains\Licensing\Http\Middleware\ValidateLicense::class,
        'license.quota' => \App\Domains\Licensing\Http\Middleware\CheckInvoiceQuota::class,
        'scope' => \App\Domains\Licensing\Http\Middleware\RequireScope::class,
        'env' => \App\Domains\Licensing\Http\Middleware\RequireEnvironment::class,
        'platform.license' => \App\Domains\Licensing\Http\Middleware\PlatformLicense::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Auth: JWT implementation
        $this->app->bind(
            Authenticator::class,
            JwtAuthenticator::class
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
        $this->reclaimMiddlewareAliases();
    }

    /**
     * Reassert the application's middleware aliases after packages have booted.
     *
     * Aliases declared in bootstrap/app.php are applied before package service
     * providers boot, so a package can overwrite one. tymon/jwt-auth claims
     * `jwt.auth` for its own Authenticate middleware from its provider.
     *
     * The application's JwtGuard is the only code that calls
     * TenantResolver::setContext(). If a package holds that alias, no JWT
     * route establishes a tenant, so admin gates deny every request and
     * tenant-scoped queries filter on organization_id = null.
     *
     * Provider boot order puts this after package providers, so the
     * application's mapping wins. MiddlewareAliasTest pins it.
     */
    private function reclaimMiddlewareAliases(): void
    {
        $router = $this->app->make(Router::class);

        foreach (self::MIDDLEWARE_ALIASES as $alias => $middleware) {
            $router->aliasMiddleware($alias, $middleware);
        }
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
