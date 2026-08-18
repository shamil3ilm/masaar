<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Regression guard for C-1 — the /admin console was registered with no
 * authentication middleware at all, exposing every organization, submission
 * log, and two state-mutating POST routes to anonymous callers.
 */
class AdminConsoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public static function adminRouteProvider(): array
    {
        return [
            'dashboard' => ['/admin'],
            'organizations' => ['/admin/organizations'],
            'organization detail' => ['/admin/organizations/00000000-0000-0000-0000-000000000000'],
            'queue' => ['/admin/queue'],
            'logs' => ['/admin/logs'],
        ];
    }

    #[DataProvider('adminRouteProvider')]
    public function test_guest_redirected(string $uri): void
    {
        $this->get($uri)->assertRedirect('/login');
    }

    #[DataProvider('adminRouteProvider')]
    public function test_non_admin_denied(string $uri): void
    {
        $this->actingAs(User::factory()->create())
            ->get($uri)
            ->assertForbidden();
    }

    /**
     * The admin console is cross-tenant. An organization's own admin holds
     * `organization_user.role = 'admin'`, which must NOT reach it.
     */
    public function test_org_admin_denied(): void
    {
        $user = User::factory()->create();
        $organization = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        $user->organizations()->attach($organization->id, ['role' => 'admin', 'status' => 'active']);

        $this->actingAs($user)
            ->get('/admin/organizations')
            ->assertForbidden();
    }

    public function test_suspended_admin_denied(): void
    {
        $user = User::factory()->platformAdmin()->suspended()->create();

        $this->actingAs($user)
            ->get('/admin/organizations')
            ->assertForbidden();
    }

    public function test_admin_allowed(): void
    {
        $this->actingAs(User::factory()->platformAdmin()->create())
            ->get('/admin/organizations')
            ->assertOk();
    }

    public function test_guest_cannot_process_queue(): void
    {
        Artisan::spy();

        $this->post('/admin/queue/process')->assertRedirect('/login');

        Artisan::shouldNotHaveReceived('call');
    }

    public function test_non_admin_cannot_process_queue(): void
    {
        Artisan::spy();

        $this->actingAs(User::factory()->create())
            ->post('/admin/queue/process')
            ->assertForbidden();

        Artisan::shouldNotHaveReceived('call');
    }

    public function test_guest_cannot_retry_item(): void
    {
        $this->post('/admin/queue/any-id/retry')->assertRedirect('/login');

        $this->assertDatabaseCount('offline_queue', 0);
    }

    public function test_non_admin_cannot_retry_item(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/admin/queue/any-id/retry')
            ->assertForbidden();

        $this->assertDatabaseCount('offline_queue', 0);
    }
}
