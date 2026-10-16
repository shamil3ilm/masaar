<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Domains\Auth\Enums\AdminPermission;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

/**
 * Every admin route names what it needs, and every action more than viewing.
 *
 * Being a platform admin was all or nothing. Roles only mean something if no
 * route is left reachable by every admin, so a route added later without a
 * permission fails here rather than quietly opening to every role.
 */
class AdminRoutePermissionTest extends TestCase
{
    public function test_every_admin_route_names_a_permission(): void
    {
        $missing = [];

        foreach ($this->adminRoutes() as $route) {
            if ($this->permissions($route) === []) {
                $missing[] = implode('|', $route->methods()).' /'.$route->uri();
            }
        }

        $this->assertSame([], $missing, "Admin routes with no admin.can permission:\n  ".implode("\n  ", $missing));
    }

    /**
     * Viewing is every role's; an action needs a permission of its own.
     */
    public function test_admin_actions_need_more_than_viewing(): void
    {
        $open = [];

        foreach ($this->adminRoutes() as $route) {
            if (in_array('GET', $route->methods(), true)) {
                continue;
            }

            if (array_diff($this->permissions($route), [AdminPermission::View->value]) === []) {
                $open[] = implode('|', $route->methods()).' /'.$route->uri();
            }
        }

        $this->assertSame([], $open, "Admin actions any role could take:\n  ".implode("\n  ", $open));
    }

    public function test_every_named_permission_exists(): void
    {
        $unknown = [];

        foreach ($this->adminRoutes() as $route) {
            foreach ($this->permissions($route) as $permission) {
                if (AdminPermission::tryFrom($permission) === null) {
                    $unknown[] = '/'.$route->uri().' names '.$permission;
                }
            }
        }

        $this->assertSame([], $unknown);
    }

    /**
     * @return list<Route>
     */
    private function adminRoutes(): array
    {
        return array_values(array_filter(
            Router::getRoutes()->getRoutes(),
            fn (Route $route): bool => in_array('platform.admin', $route->gatherMiddleware(), true),
        ));
    }

    /**
     * @return list<string>
     */
    private function permissions(Route $route): array
    {
        $names = [];

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'admin.can:')) {
                $names[] = substr($middleware, strlen('admin.can:'));
            }
        }

        return $names;
    }
}
