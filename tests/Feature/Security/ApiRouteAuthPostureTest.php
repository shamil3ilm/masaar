<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Asserts the auth posture of every registered API route.
 *
 * routes/api/public.php says it is the whole of the unauthenticated surface,
 * on the grounds that RouteAuthPostureTest fails the build for an unguarded
 * route anywhere else. That test sweeps only the routes carrying the web
 * middleware, so nothing held the claim up for the API, which is the larger
 * surface and the one a partner reaches over the internet.
 *
 * A route authenticates by one of two credentials: a JWT for a person and a
 * licence for a partner integration. Either is enough; neither is not.
 *
 * Adding a route to PUBLIC_ROUTES is the deliberate act of publishing it.
 */
class ApiRouteAuthPostureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Routes intentionally reachable without a credential, with the reason.
     */
    private const PUBLIC_ROUTES = [
        'api/health' => 'Container health probe',
        'api/license/status' => 'Lets a partner check its licence before calling anything else',
        'api/metrics' => 'Prometheus scrape, closed by the metrics middleware unless allowed by IP or token',
        'api/auth/login' => 'Credential exchange',
        'api/auth/register' => 'Account creation',
    ];

    /** Middleware that establishes who is calling. */
    private const CREDENTIALS = ['JwtGuard', 'SessionAuth', 'ValidateLicense'];

    public function test_all_api_routes_carry_a_credential(): void
    {
        $unguarded = [];

        foreach ($this->apiRoutes() as $route) {
            if (array_key_exists($route->uri(), self::PUBLIC_ROUTES)) {
                continue;
            }

            if (! $this->hasMiddleware($route, self::CREDENTIALS)) {
                $unguarded[] = implode('|', $route->methods()).' /'.$route->uri();
            }
        }

        $this->assertSame([], $unguarded, sprintf(
            'These API routes ask for no credential. Add one, or declare them in '.
            "%s::PUBLIC_ROUTES with a reason:\n  %s",
            self::class,
            implode("\n  ", $unguarded)
        ));
    }

    /** The admin API additionally requires the platform-wide privilege. */
    public function test_admin_api_requires_platform_admin(): void
    {
        $ungated = [];

        foreach ($this->apiRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/admin') && ! $this->hasMiddleware($route, ['IsPlatformAdmin'])) {
                $ungated[] = implode('|', $route->methods()).' /'.$route->uri();
            }
        }

        $this->assertSame([], $ungated, sprintf(
            "These admin API routes are reachable by any authenticated user:\n  %s",
            implode("\n  ", $ungated)
        ));
    }

    /**
     * The sweep is only worth having while it reads the routes, so an empty
     * or tiny set means the filter broke rather than that the API shrank.
     */
    public function test_the_sweep_reads_the_api(): void
    {
        $this->assertGreaterThan(50, count($this->apiRoutes()));
    }

    /**
     * Whether the route carries one of the named middleware classes.
     *
     * A route names its middleware by alias, and gatherMiddleware() hands the
     * alias back rather than the class behind it, so the alias map is what
     * turns 'jwt.auth' into the guard it stands for. Reading the map rather
     * than matching the alias text also means renaming an alias cannot quietly
     * turn this sweep into one that finds nothing.
     *
     * @param  list<string>  $names
     */
    private function hasMiddleware(RoutingRoute $route, array $names): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            $alias = explode(':', (string) $middleware)[0];
            $class = AppServiceProvider::MIDDLEWARE_ALIASES[$alias] ?? $alias;

            foreach ($names as $name) {
                if (str_contains($class, $name)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<RoutingRoute>
     */
    private function apiRoutes(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            fn (RoutingRoute $route) => str_starts_with($route->uri(), 'api/'),
        ));
    }
}
