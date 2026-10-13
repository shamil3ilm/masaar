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
    }

    /**
     * Bootstrap any application services.
     *
     * The three assertions below refuse to start a misconfigured production
     * deployment. Anything added beside them has to survive one case that is
     * easy to miss: boot() runs during `composer install`, because
     * post-autoload-dump calls `artisan package:discover` - and at that point
     * there is no .env. config/app.php reads env('APP_ENV', 'production'), so
     * the application considers itself production, while every setting the
     * guard looks at is whatever its config file defaults to.
     *
     * assertSessionsAreEncrypted did not survive it. config/session.php
     * computed its default from env('APP_ENV') without that fallback, which
     * is null rather than 'production', so sessions defaulted to unencrypted
     * in the one environment that calls itself production. The guard threw,
     * `composer install` failed, and four CI jobs and the image build went
     * down on a branch whose tests were green locally - because locally there
     * is a .env.
     *
     * So: a new guard must pass with no .env present. The cheap way to check
     * is to run `composer install` against a clean export of the tree.
     * EnvFallbackTest holds the specific trap that caused it.
     */
    public function boot(): void
    {
        $this->assertDebugDisabledInProduction();
        $this->assertSessionsAreEncrypted();
        $this->assertSubmissionLimitsAgree();
        $this->assertAuthorityIsNotSandbox();
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
    /**
     * A production platform does not file against a developer portal.
     *
     * config/fatoora.php and config/fta.php both fall back to 'sandbox', and
     * .env.example ships APP_ENV=production beside ZATCA_ENVIRONMENT=sandbox
     * and UAE_FTA_ENVIRONMENT=sandbox - so a deployment that copied it, which
     * the deployment guide says to do, submitted every invoice to ZATCA's
     * developer portal. Every submission succeeded. Nothing was legally
     * filed, no deadline was met, and no part of the platform could tell,
     * because a sandbox acceptance looks exactly like a real one.
     *
     * Simulation is allowed through on purpose. ZATCA's onboarding has a
     * simulation stage, and a production deployment genuinely passes through
     * it before going live; refusing it would make the guard the thing people
     * work around. The developer portal is the one that cannot be right.
     */
    private function assertAuthorityIsNotSandbox(): void
    {
        if (! $this->app->environment('production')) {
            return;
        }

        foreach ([
            'ZATCA_ENVIRONMENT' => 'fatoora.environment',
            'UAE_FTA_ENVIRONMENT' => 'fta.environment',
        ] as $variable => $key) {
            if (config($key) === 'sandbox') {
                throw new \RuntimeException(
                    $variable.' must not be sandbox when APP_ENV=production. '
                    .'The sandbox is the authority\'s developer portal: submissions to it '
                    .'succeed and file nothing, which no later check can detect.'
                );
            }
        }
    }

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
     * Refuse to run a production deployment that stores sessions in the clear.
     *
     * The console shows a licence's API secret exactly once, after issuing or
     * rotating it, by flashing it to the next request. A flash is session
     * data, and the shipped .env.example sets SESSION_DRIVER=database with
     * SESSION_ENCRYPT=false and APP_ENV=production - so a deployment that
     * copied it wrote that secret, in the clear, into a row of the sessions
     * table, where it stayed until the session was collected.
     *
     * config/session.php already defaults encrypt to true under production.
     * The example overrode the default to false, which is the worst kind of
     * wrong: the safe behaviour was there and the file everyone copies turned
     * it off.
     *
     * At boot rather than in a test, for the same reason as the limits below:
     * the code is right and the .env is what drifts. No driver makes this
     * acceptable - database and file leave it at rest, cookie hands it to the
     * client - so the check is on the flag alone.
     */
    private function assertSessionsAreEncrypted(): void
    {
        if ($this->app->environment('production') && config('session.encrypt') !== true) {
            throw new \RuntimeException(
                'SESSION_ENCRYPT must be true when APP_ENV=production. The console '
                .'flashes a licence API secret through the session, and with '
                .'SESSION_DRIVER='.(string) config('session.driver').' that would '
                .'be stored in the clear.'
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
