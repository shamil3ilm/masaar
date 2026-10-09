<?php

namespace App\Providers;

use App\Domains\Auth\Contracts\Authenticator;
use App\Domains\Auth\Http\Middleware\IsPlatformAdmin;
use App\Domains\Auth\Http\Middleware\JwtGuard;
use App\Domains\Auth\Services\JwtAuthenticator;
use App\Domains\Compliance\Fatoora\Config\FatooraConfig;
use App\Domains\Compliance\Fatoora\Services\CircuitBreaker;
use App\Domains\Compliance\Fatoora\Services\TimestampValidator;
use App\Domains\Licensing\Http\Middleware\CheckInvoiceQuota;
use App\Domains\Licensing\Http\Middleware\PlatformLicense;
use App\Domains\Licensing\Http\Middleware\RequireScope;
use App\Domains\Licensing\Http\Middleware\ValidateLicense;
use App\Domains\Logging\Services\ComplianceLogger;
use App\Domains\Organization\Http\Middleware\IsOrgAdmin;
use App\Domains\Organization\Http\Middleware\PortalTenant;
use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Platform\Http\Middleware\MetricsAccess;
use App\Domains\Platform\Http\Middleware\RateLimitApi;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Log;
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
        'jwt.auth' => JwtGuard::class,
        'platform.admin' => IsPlatformAdmin::class,
        'portal.tenant' => PortalTenant::class,
        'org.admin' => IsOrgAdmin::class,
        'metrics' => MetricsAccess::class,
        'rate.api' => RateLimitApi::class,
        'license' => ValidateLicense::class,
        'license.quota' => CheckInvoiceQuota::class,
        'scope' => RequireScope::class,
        'platform.license' => PlatformLicense::class,
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
        $this->app->singleton(CircuitBreaker::class);
        $this->app->singleton(ComplianceLogger::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->assertDebugDisabledInProduction();
        $this->assertSubmissionLimitsAgree();
        $this->reclaimMiddlewareAliases();

        // Eloquent drops attributes that are not fillable without a word.
        // SubmissionTracker wrote 'clearance_confirmed_at' on every ZATCA
        // response for as long as the column has not existed, so the moment a
        // document cleared was never recorded and the InvoiceCleared event
        // reported it as null. Nothing failed, which is why it lasted.
        //
        // Off in production: a deployment that meets an unexpected attribute
        // should drop it and keep serving rather than throw at the caller.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
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
     * tenant-scoped queries filter on org_id = null.
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

    /**
     * Two keys throttle submissions and the lower one silently decides.
     *
     * RateLimitApi throttles at the edge from security.rate_limits.submission;
     * SubmissionGuard throttles inside from fatoora.rate_limits.per_minute.
     * Set apart, the looser of the two is documentation for a limit that is
     * not in force, and the symptom is a 429 at a rate nobody configured.
     *
     * A deployment invariant rather than a test, because it is the .env that
     * drifts: the shipped .env.example has them equal, and a copy edited six
     * months later does not. Refused at boot in production, where a wrong
     * throughput ceiling costs submissions against the twenty-four hour
     * reporting deadline; logged elsewhere, so a developer's local override
     * does not stop the application starting.
     */
    private function assertSubmissionLimitsAgree(): void
    {
        $edge = (int) config('security.rate_limits.submission');
        $guard = FatooraConfig::getRateLimitPerMinute();

        if ($edge === $guard) {
            return;
        }

        $message = 'RATE_LIMIT_SUBMISSION and ZATCA_RATE_LIMIT_PER_MINUTE throttle '
            ."the same traffic and disagree: {$edge} at the edge against {$guard} in "
            .'SubmissionGuard. The lower one is the real limit. Set them equal.';

        if ($this->app->environment('production')) {
            throw new \RuntimeException($message);
        }

        Log::warning($message, ['edge' => $edge, 'guard' => $guard]);
    }
}
