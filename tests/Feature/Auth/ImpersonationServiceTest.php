<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Core\ActivityLog;
use App\Models\User;
use App\Services\Auth\ImpersonationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;
use Tests\Traits\TestHelpers;

class ImpersonationServiceTest extends TestCase
{
    use RefreshDatabase, TestHelpers;

    // -------------------------------------------------------------------------
    // Guard: super-admin cannot be impersonated
    // -------------------------------------------------------------------------

    public function test_cannot_impersonate_super_admin(): void
    {
        $this->setUpOrganization();

        $admin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_super_admin'  => true,
        ]);

        $target = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_super_admin'  => true,
        ]);

        $this->actingAs($admin, 'api');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Super-admin accounts cannot be impersonated.');

        app(ImpersonationService::class)->start($admin, $target, 'Testing impersonation block');
    }

    // -------------------------------------------------------------------------
    // Guard: non-super-admin without permission cannot impersonate
    // -------------------------------------------------------------------------

    public function test_non_admin_without_permission_cannot_impersonate(): void
    {
        $this->setUpOrganization();

        $admin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_super_admin'  => false,
        ]);

        $target = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_super_admin'  => false,
        ]);

        $this->actingAs($admin, 'api');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('You do not have permission to impersonate users.');

        app(ImpersonationService::class)->start($admin, $target, 'Testing permission block');
    }

    // -------------------------------------------------------------------------
    // start() returns token array with required keys
    // -------------------------------------------------------------------------

    public function test_start_returns_token_with_required_keys(): void
    {
        $this->setUpOrganization();

        $admin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_super_admin'  => true,
        ]);

        $target = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_super_admin'  => false,
        ]);

        $this->actingAs($admin, 'api');

        $result = app(ImpersonationService::class)->start($admin, $target, 'Investigating invoice display issue');

        $this->assertArrayHasKey('token', $result);
        $this->assertArrayHasKey('expires_at', $result);
        $this->assertArrayHasKey('impersonation_session_id', $result);
        $this->assertArrayHasKey('target_user', $result);
        $this->assertNotEmpty($result['token']);
        $this->assertNotEmpty($result['impersonation_session_id']);
    }

    // -------------------------------------------------------------------------
    // start() logs impersonation_started with impersonated_by_id stamped
    // -------------------------------------------------------------------------

    public function test_start_logs_impersonation_started(): void
    {
        $this->setUpOrganization();

        $admin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_super_admin'  => true,
        ]);

        $target = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_super_admin'  => false,
        ]);

        $this->actingAs($admin, 'api');

        app(ImpersonationService::class)->start($admin, $target, 'Investigating invoice display issue');

        $this->assertDatabaseHas('activity_logs', [
            'action'             => ActivityLog::ACTION_IMPERSONATION_STARTED,
            'user_id'            => $target->id,
            'impersonated_by_id' => $admin->id,
        ]);
    }

    // -------------------------------------------------------------------------
    // end() logs impersonation_ended
    // -------------------------------------------------------------------------

    public function test_end_logs_impersonation_ended(): void
    {
        $this->setUpOrganization();

        $admin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_super_admin'  => true,
        ]);

        $target = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_super_admin'  => false,
        ]);

        $this->actingAs($admin, 'api');

        $result = app(ImpersonationService::class)->start($admin, $target, 'Testing end logging');

        $sessionId = $result['impersonation_session_id'];

        // Switch context to the target (impersonating) user with the impersonation token
        $this->withToken($result['token']);

        app(ImpersonationService::class)->end($target, $admin->id, $sessionId);

        $this->assertDatabaseHas('activity_logs', [
            'action'                   => ActivityLog::ACTION_IMPERSONATION_ENDED,
            'user_id'                  => $target->id,
            'impersonated_by_id'       => $admin->id,
            'impersonation_session_id' => $sessionId,
        ]);
    }

    // -------------------------------------------------------------------------
    // Guard: nested impersonation (already impersonating) is blocked
    // -------------------------------------------------------------------------

    public function test_cannot_start_impersonation_while_already_impersonating(): void
    {
        $this->setUpOrganization();
        $admin  = User::factory()->create(['is_super_admin' => true, 'organization_id' => $this->organization->id]);
        $target = User::factory()->create(['is_super_admin' => false, 'organization_id' => $this->organization->id]);
        $target2 = User::factory()->create(['is_super_admin' => false, 'organization_id' => $this->organization->id]);

        // Start a first impersonation session so we have a JWT with is_impersonating=true
        $this->actingAs($admin, 'api');
        $result = app(ImpersonationService::class)->start($admin, $target, 'First impersonation session');

        // Act as the impersonated user using the token that carries is_impersonating=true
        $this->withToken($result['token']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already');

        app(ImpersonationService::class)->start($target, $target2, 'Trying to double-impersonate');
    }

    // -------------------------------------------------------------------------
    // Guard: cross-organization impersonation blocked for non-super-admin
    // -------------------------------------------------------------------------

    public function test_non_super_admin_cannot_impersonate_across_organizations(): void
    {
        $this->setUpOrganization();
        $orgA = $this->organization;

        // Create a second organization
        $orgB = \App\Models\Core\Organization::factory()->create();

        // Give admin the impersonate_users permission so we reach the org-check guard
        $role = \App\Models\Core\Role::factory()->create([
            'organization_id' => $orgA->id,
            'slug'            => 'impersonator-role',
            'name'            => 'Impersonator',
        ]);

        $permission = \App\Models\Core\Permission::firstOrCreate(
            ['slug' => 'impersonate_users'],
            ['name' => 'Impersonate Users', 'module' => 'core'],
        );

        $role->permissions()->attach($permission->id);

        $admin = User::factory()->create([
            'organization_id' => $orgA->id,
            'is_super_admin'  => false,
        ]);

        $admin->roles()->attach($role->id);

        $target = User::factory()->create([
            'organization_id' => $orgB->id,
            'is_super_admin'  => false,
        ]);

        $this->actingAs($admin, 'api');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('You can only impersonate users within your organization.');

        app(ImpersonationService::class)->start($admin, $target, 'Cross-org attempt');
    }
}
