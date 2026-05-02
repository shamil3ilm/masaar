<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Http\Middleware\TrackImpersonation;
use App\Models\Core\ActivityLog;
use App\Models\Core\Organization;
use App\Models\User;
use App\Services\Auth\ImpersonationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;
use Tests\Traits\TestHelpers;

class ImpersonationTest extends TestCase
{
    use RefreshDatabase, TestHelpers;

    // ----- ActivityLog model -----

    public function test_activity_log_has_impersonation_constants(): void
    {
        $this->assertSame('impersonation_started', ActivityLog::ACTION_IMPERSONATION_STARTED);
        $this->assertSame('impersonation_ended', ActivityLog::ACTION_IMPERSONATION_ENDED);
    }

    public function test_activity_log_fillable_includes_impersonation_fields(): void
    {
        $log      = new ActivityLog();
        $fillable = $log->getFillable();

        $this->assertContains('impersonated_by_id', $fillable);
        $this->assertContains('impersonation_session_id', $fillable);
    }

    // ----- ActivityLogService auto-stamp -----

    public function test_activity_log_service_stamps_impersonation_context_on_critical_actions(): void
    {
        $this->setUpOrganization();
        $admin  = User::factory()->create(['organization_id' => $this->organization->id]);
        $target = User::factory()->create(['organization_id' => $this->organization->id]);

        $sessionId = Str::uuid()->toString();

        $request = Request::create('/test');
        $request->attributes->set('impersonated_by_id', $admin->id);
        $request->attributes->set('impersonation_session_id', $sessionId);
        app()->instance('request', $request);

        $this->actingAs($target, 'api');

        $log = app(\App\Services\Core\ActivityLogService::class)->log([
            'action'      => ActivityLog::ACTION_UPDATED,
            'entity_type' => 'Invoice',
            'entity_id'   => 1,
            'entity_name' => 'INV-001',
            'description' => 'Invoice updated during impersonation',
        ]);

        $this->assertSame($admin->id, $log->impersonated_by_id);
        $this->assertSame($sessionId, $log->impersonation_session_id);
    }

    public function test_activity_log_service_does_not_stamp_viewed_actions(): void
    {
        $this->setUpOrganization();
        $admin  = User::factory()->create(['organization_id' => $this->organization->id]);
        $target = User::factory()->create(['organization_id' => $this->organization->id]);

        $request = Request::create('/test');
        $request->attributes->set('impersonated_by_id', $admin->id);
        $request->attributes->set('impersonation_session_id', Str::uuid()->toString());
        app()->instance('request', $request);

        $this->actingAs($target, 'api');

        $log = app(\App\Services\Core\ActivityLogService::class)->log([
            'action'      => ActivityLog::ACTION_VIEWED,
            'entity_type' => 'Invoice',
            'entity_id'   => 1,
            'entity_name' => 'INV-001',
            'description' => 'Invoice viewed',
        ]);

        $this->assertNull($log->impersonated_by_id);
        $this->assertNull($log->impersonation_session_id);
    }

    // ----- TrackImpersonation middleware -----

    public function test_track_impersonation_middleware_exists(): void
    {
        $this->assertTrue(class_exists(TrackImpersonation::class));
        $this->assertTrue(method_exists(TrackImpersonation::class, 'handle'));
    }

    // ----- ImpersonationService -----

    public function test_impersonation_service_exists(): void
    {
        $this->assertTrue(class_exists(ImpersonationService::class));
    }

    public function test_impersonation_service_rejects_super_admin_target(): void
    {
        $this->setUpOrganization();
        $admin  = User::factory()->create(['organization_id' => $this->organization->id, 'is_super_admin' => true]);
        $target = User::factory()->create(['organization_id' => $this->organization->id, 'is_super_admin' => true]);

        $this->actingAs($admin, 'api');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Super-admin accounts cannot be impersonated.');

        app(ImpersonationService::class)->start($admin, $target, 'Testing the block');
    }

    public function test_impersonation_service_rejects_non_permitted_admin(): void
    {
        $this->setUpOrganization();
        $admin  = User::factory()->create(['organization_id' => $this->organization->id, 'is_super_admin' => false]);
        $target = User::factory()->create(['organization_id' => $this->organization->id, 'is_super_admin' => false]);

        $this->actingAs($admin, 'api');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('You do not have permission to impersonate users.');

        app(ImpersonationService::class)->start($admin, $target, 'No permission attempt');
    }

    // ----- ImpersonationController endpoints -----

    public function test_super_admin_can_start_impersonation(): void
    {
        $this->setUpOrganization();
        $admin  = User::factory()->superAdmin()->create(['organization_id' => $this->organization->id]);
        $target = User::factory()->create(['organization_id' => $this->organization->id, 'is_super_admin' => false]);
        $token  = JWTAuth::fromUser($admin);

        $response = $this->withToken($token)
            ->postJson("/api/v1/auth/impersonate/{$target->id}", [
                'reason' => 'Investigating reported invoice display issue',
            ]);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['token', 'expires_at', 'impersonation_session_id'],
            ]);
    }

    public function test_reason_is_required_to_start_impersonation(): void
    {
        $this->setUpOrganization();
        $admin  = User::factory()->superAdmin()->create(['organization_id' => $this->organization->id]);
        $target = User::factory()->create(['organization_id' => $this->organization->id]);
        $token  = JWTAuth::fromUser($admin);

        $response = $this->withToken($token)
            ->postJson("/api/v1/auth/impersonate/{$target->id}", []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_cannot_impersonate_super_admin_via_endpoint(): void
    {
        $this->setUpOrganization();
        $admin  = User::factory()->superAdmin()->create(['organization_id' => $this->organization->id]);
        $target = User::factory()->superAdmin()->create(['organization_id' => $this->organization->id]);
        $token  = JWTAuth::fromUser($admin);

        $response = $this->withToken($token)
            ->postJson("/api/v1/auth/impersonate/{$target->id}", [
                'reason' => 'Attempting to impersonate a super admin',
            ]);

        $response->assertStatus(403);
    }

    public function test_user_without_permission_cannot_impersonate(): void
    {
        $this->setUpOrganization();
        $admin  = User::factory()->create(['organization_id' => $this->organization->id, 'is_super_admin' => false]);
        $target = User::factory()->create(['organization_id' => $this->organization->id]);
        $token  = JWTAuth::fromUser($admin);

        $response = $this->withToken($token)
            ->postJson("/api/v1/auth/impersonate/{$target->id}", [
                'reason' => 'No permission attempt',
            ]);

        $response->assertStatus(403);
    }

    // ----- ImpersonationAuditController -----

    public function test_super_admin_can_list_impersonation_sessions(): void
    {
        $this->setUpOrganization();
        $superAdmin = User::factory()->superAdmin()->create(['organization_id' => $this->organization->id]);
        $token      = JWTAuth::fromUser($superAdmin);

        ActivityLog::factory()->create([
            'action'                   => ActivityLog::ACTION_IMPERSONATION_STARTED,
            'impersonation_session_id' => Str::uuid()->toString(),
            'impersonated_by_id'       => $superAdmin->id,
            'organization_id'          => $this->organization->id,
        ]);

        $response = $this->withToken($token)
            ->getJson('/api/v1/admin/impersonation-sessions');

        $response->assertOk()
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_non_super_admin_cannot_list_impersonation_sessions(): void
    {
        $this->setUpOrganization();
        $user  = User::factory()->create(['is_super_admin' => false, 'organization_id' => $this->organization->id]);
        $token = JWTAuth::fromUser($user);

        $response = $this->withToken($token)
            ->getJson('/api/v1/admin/impersonation-sessions');

        $response->assertForbidden();
    }
}