<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Asserts the auth posture of every registered Blade route.
 *
 * C-1 and C-2 both existed because a route group was registered without
 * middleware and nothing noticed. Per-route tests only cover the routes someone
 * remembered to write a test for; this sweeps the router itself, so a new
 * unguarded route fails here the moment it is added.
 *
 * Adding a route to PUBLIC_ROUTES is the deliberate act of publishing it.
 */
class RouteAuthPostureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Routes intentionally reachable without a session, with the reason.
     */
    private const PUBLIC_ROUTES = [
        '/' => 'Marketing landing page',
        'login' => 'Credential entry form',
        'up' => 'Container health probe',
    ];

    public function test_every_web_route_is_either_authenticated_or_declared_public(): void
    {
        $unguarded = [];

        foreach ($this->webRoutes() as $route) {
            $uri = $route->uri();

            if (array_key_exists($uri, self::PUBLIC_ROUTES)) {
                continue;
            }

            $middleware = $route->gatherMiddleware();

            if (! in_array('auth', $middleware, true)) {
                $unguarded[] = implode('|', $route->methods()).' /'.$uri;
            }
        }

        $this->assertSame([], $unguarded, sprintf(
            "These web routes carry no 'auth' middleware. Add authentication, or ".
            "declare them in %s::PUBLIC_ROUTES with a reason:\n  %s",
            self::class,
            implode("\n  ", $unguarded)
        ));
    }

    /**
     * The admin console additionally requires the cross-tenant privilege, and
     * the portal additionally requires session-derived tenant resolution.
     */
    public function test_admin_and_portal_routes_carry_their_authorization_middleware(): void
    {
        foreach ($this->webRoutes() as $route) {
            $uri = '/'.$route->uri();
            $middleware = $route->gatherMiddleware();

            if (str_starts_with($uri, '/admin')) {
                $this->assertContains('platform.admin', $middleware, "{$uri} is missing platform.admin");
            }

            // /portal/switch deliberately runs without tenant resolution: it
            // clears the selection.
            if (str_starts_with($uri, '/portal') && $uri !== '/portal/switch') {
                $this->assertContains('portal.tenant', $middleware, "{$uri} is missing portal.tenant");
            }
        }
    }

    public function test_login_form_is_reachable_by_a_guest(): void
    {
        $this->get('/login')->assertOk();
    }

    /**
     * @return list<RoutingRoute>
     */
    private function webRoutes(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            fn (RoutingRoute $route) => in_array('web', $route->gatherMiddleware(), true)
                || in_array('web', (array) ($route->getAction('middleware') ?? []), true)
        ));
    }
}
