<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * The JSON admin API is cross-tenant: it reports statistics over every
 * organization on the platform.
 *
 * It was gated by the `admin` alias, which checks role=admin inside the JWT's
 * organization context — a role any customer's own org-admin carries. That
 * went unnoticed because TenantResolver was never populated (see
 * MiddlewareAliasTest), so the check denied everyone and looked safe. Once the
 * tenant context started working, it would have admitted one tenant's admin to
 * every other tenant's figures.
 */
class AdminApiAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_routes_use_platform_gate(): void
    {
        $router = app(Router::class);
        $aliases = $router->getMiddleware();

        $adminRoutes = collect($router->getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/admin/'));

        $this->assertNotEmpty($adminRoutes, 'no api/admin routes registered');

        foreach ($adminRoutes as $route) {
            $resolved = array_map(
                fn (string $m) => $aliases[$m] ?? $m,
                $route->gatherMiddleware()
            );

            $this->assertContains(
                \App\Domains\Auth\Http\Middleware\IsPlatformAdmin::class,
                $resolved,
                "/{$route->uri()} is missing the platform-admin gate."
            );

            $this->assertNotContains(
                \App\Domains\Auth\Http\Middleware\IsAdmin::class,
                $resolved,
                "/{$route->uri()} uses the per-organization admin gate, which any "
                ."customer's org-admin satisfies."
            );
        }
    }

    public function test_guest_denied(): void
    {
        $this->getJson('/api/admin/dashboard')->assertUnauthorized();
    }

    /**
     * An org-admin carries role=admin in their token's organization context.
     * That is exactly the credential the old gate accepted.
     */
    public function test_org_admin_denied(): void
    {
        $user = User::factory()->create();
        $organization = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        $user->organizations()->attach($organization->id, ['role' => 'admin', 'status' => 'active']);

        $this->withToken($this->tokenFor($user, $organization->id, 'admin'))
            ->getJson('/api/admin/dashboard')
            ->assertForbidden();
    }

    public function test_platform_admin_allowed(): void
    {
        $user = User::factory()->platformAdmin()->create();

        // Only authorization is under test; whatever the dashboard then
        // reports, it must not be an auth failure.
        $response = $this->withToken($this->tokenFor($user))->getJson('/api/admin/dashboard');

        $this->assertNotSame(403, $response->getStatusCode());
        $this->assertNotSame(401, $response->getStatusCode());
    }

    /**
     * A real signed token: JwtGuard calls JWTAuth::parseToken(), so
     * actingAs() (which only seeds the guard) does not exercise this path.
     */
    private function tokenFor(User $user, ?string $organizationId = null, string $role = 'member'): string
    {
        $claims = $organizationId === null ? [] : ['org_id' => $organizationId, 'role' => $role];

        return JWTAuth::claims($claims)->fromUser($user);
    }
}
