<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dashboard shows the platform, not zeros.
 *
 * It rendered five unauthenticated fetch() calls against jwt.auth routes.
 * Every one answered 401 and every `|| {}` fallback turned that into an empty
 * panel, so an outage and a quiet Sunday looked identical.
 */
class DashboardDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dashboard_counts_organizations(): void
    {
        Organization::create(['name' => 'Acme', 'country' => 'SA']);
        Organization::create(['name' => 'Rival', 'country' => 'SA']);

        $admin = User::factory()->create(['is_platform_admin' => true]);

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertViewHas('overview', fn (array $o) => $o['organizations']['total'] === 2);
    }

    /**
     * The view must not reach back to an API it cannot authenticate against.
     */
    public function test_the_dashboard_fetches_nothing(): void
    {
        $source = (string) file_get_contents(resource_path('views/admin/dashboard.blade.php'));

        $this->assertStringNotContainsString('fetch(', $source);
    }
}
