<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Core\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;
use Tests\Traits\TestHelpers;

class ImpersonationAuditControllerTest extends TestCase
{
    use RefreshDatabase, TestHelpers;

    // -----------------------------------------------------------------------
    // index — GET /api/v1/admin/impersonation-sessions
    // -----------------------------------------------------------------------

    public function test_super_admin_can_list_sessions(): void
    {
        // Create an org so the super-admin has an organization_id and the
        // global tenant scope can resolve activity logs correctly.
        $this->setUpOrganization();

        $admin  = User::factory()->superAdmin()->create(['organization_id' => $this->organization->id]);
        $target = User::factory()->create(['organization_id' => $this->organization->id]);

        $sessionId = 'sess-' . Str::uuid();

        ActivityLog::forceCreate([
            'organization_id'          => $this->organization->id,
            'user_id'                  => $target->id,
            'impersonated_by_id'       => $admin->id,
            'impersonation_session_id' => $sessionId,
            'action'                   => ActivityLog::ACTION_IMPERSONATION_STARTED,
            'entity_type'              => 'user',
            'entity_id'                => (string) $target->id,
            'description'              => 'Admin started impersonation session',
            'ip_address'               => '127.0.0.1',
        ]);

        $token    = JWTAuth::fromUser($admin);
        $response = $this->getJson('/api/v1/admin/impersonation-sessions', [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ]);

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'data',
                'meta' => ['current_page', 'per_page', 'total'],
            ]);
    }

    public function test_non_super_admin_cannot_list_sessions(): void
    {
        $this->setUpOrganization();
        $this->setUpAuthenticatedUser();

        $response = $this->getJson('/api/v1/admin/impersonation-sessions', [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept'        => 'application/json',
        ]);

        $response->assertStatus(403)
            ->assertJson(['success' => false]);
    }

    // -----------------------------------------------------------------------
    // show — GET /api/v1/admin/impersonation-sessions/{session_id}
    // -----------------------------------------------------------------------

    public function test_can_show_session_detail(): void
    {
        $this->setUpOrganization();

        $admin  = User::factory()->superAdmin()->create(['organization_id' => $this->organization->id]);
        $target = User::factory()->create(['organization_id' => $this->organization->id]);

        $sessionId = 'sess-' . Str::uuid();

        // Start event
        ActivityLog::forceCreate([
            'organization_id'          => $this->organization->id,
            'user_id'                  => $target->id,
            'impersonated_by_id'       => $admin->id,
            'impersonation_session_id' => $sessionId,
            'action'                   => ActivityLog::ACTION_IMPERSONATION_STARTED,
            'entity_type'              => 'user',
            'entity_id'                => (string) $target->id,
            'description'              => 'Admin started impersonation session',
            'ip_address'               => '127.0.0.1',
            'metadata'                 => ['reason' => 'Support investigation'],
        ]);

        // An action taken during the session
        ActivityLog::forceCreate([
            'organization_id'          => $this->organization->id,
            'user_id'                  => $target->id,
            'impersonated_by_id'       => $admin->id,
            'impersonation_session_id' => $sessionId,
            'action'                   => ActivityLog::ACTION_VIEWED,
            'entity_type'              => 'invoice',
            'entity_id'                => '42',
            'description'              => 'Viewed invoice during impersonation',
            'ip_address'               => '127.0.0.1',
        ]);

        // End event
        ActivityLog::forceCreate([
            'organization_id'          => $this->organization->id,
            'user_id'                  => $target->id,
            'impersonated_by_id'       => $admin->id,
            'impersonation_session_id' => $sessionId,
            'action'                   => ActivityLog::ACTION_IMPERSONATION_ENDED,
            'entity_type'              => 'user',
            'entity_id'                => (string) $target->id,
            'description'              => 'Admin ended impersonation session',
            'ip_address'               => '127.0.0.1',
        ]);

        $token    = JWTAuth::fromUser($admin);
        $response = $this->getJson("/api/v1/admin/impersonation-sessions/{$sessionId}", [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ]);

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'data' => [
                    'session_id',
                    'started_at',
                    'admin',
                    'target_user',
                    'actions',
                ],
            ]);

        // 'actions' must be an array and must contain only the mid-session entry
        $actions = $response->json('data.actions');
        $this->assertIsArray($actions);
        $this->assertCount(1, $actions);
        $this->assertSame(ActivityLog::ACTION_VIEWED, $actions[0]['action']);
    }

    public function test_non_super_admin_cannot_access_session_detail(): void
    {
        $this->setUpOrganization();
        $regularUser = User::factory()->create(['is_super_admin' => false, 'organization_id' => $this->organization->id]);

        $token = JWTAuth::fromUser($regularUser);
        $response = $this->getJson(route('admin.impersonation-sessions.show', 'any-session-id'), [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ]);

        $response->assertStatus(403);
    }

    public function test_show_returns_404_for_unknown_session(): void
    {
        $this->setUpOrganization();

        $admin = User::factory()->superAdmin()->create(['organization_id' => $this->organization->id]);

        $token    = JWTAuth::fromUser($admin);
        $response = $this->getJson('/api/v1/admin/impersonation-sessions/non-existent-session-uuid-99999', [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ]);

        $response->assertNotFound()
            ->assertJson(['success' => false]);
    }
}
