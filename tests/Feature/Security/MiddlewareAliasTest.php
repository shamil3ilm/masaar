<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domains\Auth\Http\Middleware\IsPlatformAdmin;
use App\Domains\Auth\Http\Middleware\JwtGuard;
use App\Domains\Licensing\Http\Middleware\PlatformLicense;
use App\Domains\Licensing\Http\Middleware\ValidateLicense;
use App\Domains\Organization\Http\Middleware\PortalTenant;
use App\Domains\Platform\Http\Middleware\MetricsAccess;
use App\Domains\Platform\Http\Middleware\RateLimitApi;
use Illuminate\Routing\Router;
use Tests\TestCase;

/**
 * Guards the middleware alias table against silent capture by a package.
 *
 * tymon/jwt-auth registers its own `jwt.auth` alias from its service
 * provider's boot(). Package providers boot after the aliases declared in
 * bootstrap/app.php are applied, so the package's registration silently
 * replaced the application's — with no error and nothing in route:list to
 * suggest the app ever wanted something different.
 *
 * The consequence was not cosmetic. App\Domains\Auth\Http\Middleware\
 * JwtGuard is the only code that calls TenantResolver::setContext(),
 * so with it out of the pipeline the tenant context was never populated:
 * every JWT route saw a null organization, the admin gate denied every
 * request, and tenant-scoped queries filtered on org_id = null.
 */
class MiddlewareAliasTest extends TestCase
{
    /**
     * Aliases the application declares and must own, whatever packages do.
     */
    private const OWNED = [
        'jwt.auth' => JwtGuard::class,
        'platform.admin' => IsPlatformAdmin::class,
        'portal.tenant' => PortalTenant::class,
        'metrics' => MetricsAccess::class,
        'rate.api' => RateLimitApi::class,
        'license' => ValidateLicense::class,
        'platform.license' => PlatformLicense::class,
    ];

    public function test_aliases_not_hijacked(): void
    {
        $registered = app(Router::class)->getMiddleware();

        foreach (self::OWNED as $alias => $expected) {
            $this->assertSame(
                $expected,
                $registered[$alias] ?? null,
                "Middleware alias '{$alias}' does not resolve to the application's class. "
                .'A package provider has most likely claimed it.'
            );
        }
    }

    /**
     * The JWT pipeline must include the middleware that establishes tenant
     * context, or every downstream tenant check silently sees no tenant.
     */
    public function test_jwt_sets_tenant_context(): void
    {
        $router = app(Router::class);

        $route = collect($router->getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/invoices');

        $this->assertNotNull($route, 'api/invoices route is missing');

        // gatherMiddleware() returns the alias strings, so resolve them
        // through the router's table to get the classes that actually run.
        $aliases = $router->getMiddleware();
        $resolved = array_map(
            fn (string $m) => $aliases[$m] ?? $m,
            $route->gatherMiddleware()
        );

        $this->assertContains(
            JwtGuard::class,
            $resolved,
            'api/invoices does not run JwtGuard, so TenantResolver is never populated.'
        );
    }
}
