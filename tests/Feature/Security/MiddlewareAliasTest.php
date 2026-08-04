<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

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
 * request, and tenant-scoped queries filtered on organization_id = null.
 */
class MiddlewareAliasTest extends TestCase
{
    /**
     * Aliases the application declares and must own, whatever packages do.
     */
    private const OWNED = [
        'jwt.auth' => \App\Domains\Auth\Http\Middleware\JwtGuard::class,
        'platform.admin' => \App\Domains\Auth\Http\Middleware\IsPlatformAdmin::class,
        'api.key' => \App\Domains\Auth\Http\Middleware\ApiKeyAuth::class,
        'portal.tenant' => \App\Domains\Organization\Http\Middleware\PortalTenant::class,
        'metrics' => \App\Domains\Platform\Http\Middleware\MetricsAccess::class,
        'rate.api' => \App\Domains\Platform\Http\Middleware\RateLimitApi::class,
        'license' => \App\Domains\Licensing\Http\Middleware\ValidateLicense::class,
        'platform.license' => \App\Domains\Licensing\Http\Middleware\PlatformLicense::class,
    ];

    public function test_aliases_not_hijacked(): void
    {
        $registered = app(Router::class)->getMiddleware();

        foreach (self::OWNED as $alias => $expected) {
            $this->assertSame(
                $expected,
                $registered[$alias] ?? null,
                "Middleware alias '{$alias}' does not resolve to the application's class. "
                ."A package provider has most likely claimed it."
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
            \App\Domains\Auth\Http\Middleware\JwtGuard::class,
            $resolved,
            'api/invoices does not run JwtGuard, so TenantResolver is never populated.'
        );
    }
}
