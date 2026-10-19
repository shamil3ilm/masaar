<?php

namespace App\Providers;

use App\Domains\Auth\Contracts\Authenticator;
use App\Domains\Auth\Http\Middleware\AdminCan;
use App\Domains\Auth\Http\Middleware\IsPlatformAdmin;
use App\Domains\Auth\Http\Middleware\JwtGuard;
use App\Domains\Auth\Services\JwtAuthenticator;
use App\Domains\Branding\Http\ThemeComposer;
use App\Domains\Branding\Services\ThemeService;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\CircuitBreaker;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\TimestampValidator;
use App\Domains\Compliance\Countries\SaudiArabia\Http\Middleware\FilingCleared;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraConfig;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Http\Middleware\UaeInvoicing;
use App\Domains\Compliance\Engine\Http\Middleware\JurisdictionAvailable;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Policies\InvoicePolicy;
use App\Domains\Licensing\Http\Middleware\CheckInvoiceQuota;
use App\Domains\Licensing\Http\Middleware\PlatformLicense;
use App\Domains\Licensing\Http\Middleware\RequireLicenseFeature;
use App\Domains\Licensing\Http\Middleware\RequireScope;
use App\Domains\Licensing\Http\Middleware\ValidateLicense;
use App\Domains\Organization\Contracts\WorkingBranchStore;
use App\Domains\Organization\Http\Middleware\IsOrgAdmin;
use App\Domains\Organization\Http\Middleware\OrgWriter;
use App\Domains\Organization\Http\Middleware\PortalBranch;
use App\Domains\Organization\Http\Middleware\PortalTenant;
use App\Domains\Organization\Http\Middleware\TwoFactorRequired;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\CustomizedWorkingBranch;
use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Platform\Access\PortalAccess;
use App\Domains\Platform\Http\Middleware\AuditAdminAction;
use App\Domains\Platform\Http\Middleware\MetricsAccess;
use App\Domains\Platform\Http\Middleware\PersonAccess;
use App\Domains\Platform\Http\Middleware\RateLimitApi;
use App\Domains\Platform\Services\PublicSite;
use App\Domains\Platform\Services\SavedListViews;
use App\Http\Listing\SavedViewStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Paginator;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
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
        'admin.can' => AdminCan::class,
        'admin.audit' => AuditAdminAction::class,
        'access.person' => PersonAccess::class,
        'portal.tenant' => PortalTenant::class,
        'portal.branch' => PortalBranch::class,
        'org.admin' => IsOrgAdmin::class,
        'org.writer' => OrgWriter::class,
        'org.two_factor' => TwoFactorRequired::class,
        'metrics' => MetricsAccess::class,
        'rate.api' => RateLimitApi::class,
        'license' => ValidateLicense::class,
        'license.quota' => CheckInvoiceQuota::class,
        'scope' => RequireScope::class,
        'license.feature' => RequireLicenseFeature::class,
        'platform.license' => PlatformLicense::class,
        'filing.cleared' => FilingCleared::class,
        'uae.invoicing' => UaeInvoicing::class,
        'jurisdiction.available' => JurisdictionAvailable::class,
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

        // Saved views on the history lists: records a person makes, names and
        // removes, so a table of their own rather than settings.
        $this->app->bind(SavedViewStore::class, SavedListViews::class);

        // The branch switcher's choice: a person's own customization.
        $this->app->bind(WorkingBranchStore::class, CustomizedWorkingBranch::class);

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
        // The guards protect a platform that serves requests and files
        // invoices. Package discovery does neither: Composer runs it from
        // post-autoload-dump, before any .env exists, to read package metadata.
        //
        // With no .env the defaults apply - APP_ENV is 'production' and
        // ZATCA_ENVIRONMENT is 'sandbox' - which is exactly the pairing
        // assertAuthorityIsNotSandbox refuses. So the guard broke
        // `composer install` itself, on every machine that ran it before
        // writing an environment. CI does, and failed at "Install
        // dependencies" on every push from the commit that added the guard:
        // thirty-two runs in which no test ran at all, while the suite was
        // being reported green from a local SQLite run.
        //
        // Every other command still checks, including the ones a deploy runs
        // - migrate, config:cache, queue:work - because those are where a
        // misconfigured platform should be stopped.
        if (! $this->app->runningConsoleCommand('package:discover')) {
            $this->assertDebugDisabledInProduction();
            $this->assertSessionsAreEncrypted();
            $this->assertSubmissionLimitsAgree();
            $this->assertAuthorityIsNotSandbox();
            $this->assertAuthorityIsKnown();
            $this->assertLicenceSecretIsSet();
            $this->assertProductionSettingsAreFilledIn();
        }

        $this->reclaimMiddlewareAliases();

        // Who may read and issue an invoice, by the branch it belongs to.
        Gate::policy(Invoice::class, InvoicePolicy::class);

        // @admincan('licences.manage') ... @endadmincan: an action shown only
        // to an admin whose role allows it. The route refuses the rest
        // anyway (admin.can); this keeps them from a button that only 403s.
        Blade::if('admincan', fn (string $permission): bool => (bool) auth()->user()?->canAdmin($permission));

        // @canuse('issue_invoices') ... @endcanuse: a portal action shown only
        // when Access lets this person in this organization take it. The
        // action still checks; this keeps people from a button that refuses.
        Blade::if('canuse', fn (string $feature): bool => $this->app->make(PortalAccess::class)->allows($feature));

        $this->configureRootUrl();

        // The platform's colours, name and logo - and an organization's over
        // them on its own pages - for every view that draws a page's frame.
        View::composer(ThemeComposer::VIEWS, ThemeComposer::class);

        // Every ->links() in the design system's pagination, rather than the
        // framework's stock-grey one with its arrows the wrong way in Arabic.
        Paginator::defaultView('components.ui.pagination');
        Paginator::defaultSimpleView('components.ui.pagination');

        // The public site's switches - the parent brand's signs, where contact
        // goes - and its countries, for the public pages that show them, and
        // only those.
        View::composer(['layouts.public', 'welcome', 'home.*', 'errors::404'], fn (\Illuminate\View\View $view) => $view->with('publicSite', $this->app->make(PublicSite::class)));

        // After the delete has happened - an organization with invoices
        // cannot be deleted, and its branding should not go if it stays.
        Organization::deleted(fn (Organization $organization) => $this->app->make(ThemeService::class)
            ->forgetOrganization((string) $organization->id));

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
     * Where the URLs the application builds - links, form actions, assets,
     * mail - point.
     *
     * Everywhere but a developer's machine they are built from APP_URL, never
     * from the request's Host header: from the header, anyone could ask for a
     * victim's password-reset link and have it point at a host they control.
     *
     * On a local machine (APP_ENV=local) they follow the address being
     * browsed, so php artisan serve on 127.0.0.1:8000, localhost or a local
     * virtual host all work without editing APP_URL. A request-less context -
     * a queued mail, a command - still falls back to APP_URL.
     */
    public function configureRootUrl(): void
    {
        if (blank(config('app.url')) || $this->app->environment('local')) {
            return;
        }

        URL::forceRootUrl((string) config('app.url'));

        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
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
     * config/fatoora.php falls back to 'sandbox', and .env.example shipped
     * APP_ENV=production beside ZATCA_ENVIRONMENT=sandbox - so a deployment
     * that copied it, which
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

        foreach ($this->authorities() as $variable => $key) {
            if (config($key.'.environment') === 'sandbox') {
                throw new \RuntimeException(
                    $variable.' must not be sandbox when APP_ENV=production. '
                    .'The sandbox is the authority\'s developer portal: submissions to it '
                    .'succeed and file nothing, which no later check can detect.'
                );
            }
        }
    }

    /**
     * An authority environment is one the configuration has an endpoint for.
     *
     * Anything else fell back to the sandbox's endpoint, so
     * ZATCA_ENVIRONMENT=Production, =prod or a typo filed every invoice with
     * the developer portal - and passed the check above, which looks for the
     * word 'sandbox'. Checked in every environment: a name with no endpoint
     * is wrong everywhere.
     */
    private function assertAuthorityIsKnown(): void
    {
        foreach ($this->authorities() as $variable => $key) {
            $environment = config($key.'.environment');
            $known = array_keys((array) config($key.'.endpoints', []));

            if (! is_string($environment) || ! in_array($environment, $known, true)) {
                throw new \RuntimeException(
                    $variable.' is '.var_export($environment, true).', which is not one of: '
                    .implode(', ', $known).'. An unrecognised name used to file with the sandbox.'
                );
            }
        }
    }

    /**
     * The authorities this deployment files with directly: ZATCA.
     *
     * The UAE is not one. Its documents go through an accredited service
     * provider, which has no sandbox switch here - see peppolProviderUnset().
     *
     * @return array<string, string> environment variable => config file
     */
    private function authorities(): array
    {
        return ['ZATCA_ENVIRONMENT' => 'fatoora'];
    }

    /**
     * What only the operator can supply, supplied, before production serves.
     *
     * Each has a default that works on a developer's machine and is wrong in
     * production, silently: links in mail and in enrolment pointing at
     * localhost, mail written only to the log, tokens signed with nothing, a
     * key pepper that cannot be added later without invalidating every key.
     *
     * Only where the platform serves - requests, queue workers, the
     * scheduler. A one-off command is how several of these get filled in
     * (jwt:secret writes JWT_SECRET), so refusing those would block the fix.
     */
    private function assertProductionSettingsAreFilledIn(): void
    {
        if (! $this->app->environment('production') || ! $this->serves()) {
            return;
        }

        $url = (string) config('app.url');
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $from = strtolower((string) config('mail.from.address'));

        $unset = array_keys(array_filter([
            'APP_URL' => $host === '' || str_contains($url, '<') || in_array(trim($host, '[]'), ['localhost', '127.0.0.1', '::1'], true),
            'JWT_SECRET' => $this->blank(config('jwt.secret')),
            'API_KEY_PEPPER' => $this->blank(config('security.api_key_pepper')),
            'MAIL_MAILER' => in_array(config('mail.default'), ['log', 'array'], true),
            'MAIL_FROM_ADDRESS' => $this->blank($from) || str_ends_with($from, '@example.com'),
            ...$this->peppolProviderUnset(),
        ]));

        if ($unset !== []) {
            throw new \RuntimeException(
                'Set these before APP_ENV=production serves: '.implode(', ', $unset).'. '
                .'Each has a default that works locally and is silently wrong in production - '
                .'see the comments on them in .env.example.'
            );
        }
    }

    /**
     * A connected UAE service provider has its credentials.
     *
     * Only when a provider's base URL is set. With no URL the platform keeps
     * documents for upload by hand, which needs nothing; with one, a missing
     * secret means every send is refused at the provider and every callback
     * is unverifiable - found at the first invoice, not here.
     *
     * Whether the UAE is on does not enter into it. A platform admin can
     * switch the country on from the console at any time, long after this
     * guard ran, and boot is no place to ask the database for that switch.
     *
     * @return array<string, bool>
     */
    private function peppolProviderUnset(): array
    {
        if ($this->blank(config('peppol.asp.base_url'))) {
            return [];
        }

        return [
            'PEPPOL_ASP_CLIENT_ID' => $this->blank(config('peppol.asp.client_id')),
            'PEPPOL_ASP_CLIENT_SECRET' => $this->blank(config('peppol.asp.client_secret')),
            'PEPPOL_ASP_WEBHOOK_SECRET' => $this->blank(config('peppol.asp.webhook_secret')),
        ];
    }

    private function serves(): bool
    {
        return ! $this->app->runningInConsole()
            || $this->app->runningConsoleCommand('queue:work', 'queue:listen', 'schedule:run', 'schedule:work', 'serve', 'octane:start');
    }

    /**
     * Empty, or still the template's <PLACEHOLDER>.
     */
    private function blank(mixed $value): bool
    {
        return ! is_string($value) || trim($value) === '' || str_contains($value, '<');
    }

    /**
     * A production deployment that enforces licences can verify them.
     *
     * Without PLATFORM_LICENSE_SECRET no licence key verifies, so every API
     * request is refused - the service once covered for that with a default
     * secret written in its source, which accepted keys anyone could sign.
     * Refused here instead, at boot, where the cause is named. A deployment
     * that does not enforce licences says so with PLATFORM_LICENSE_ENABLED=false.
     */
    private function assertLicenceSecretIsSet(): void
    {
        if (! $this->app->environment('production') || ! config('platform-license.enabled', true)) {
            return;
        }

        $secret = config('platform-license.signing_secret');

        if (! is_string($secret) || $secret === '') {
            throw new \RuntimeException(
                'PLATFORM_LICENSE_SECRET must be set when APP_ENV=production and licensing is enabled. '
                .'Without it no licence key verifies and every API request is refused. '
                .'Set it, or set PLATFORM_LICENSE_ENABLED=false for a deployment that does not enforce licences.'
            );
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
