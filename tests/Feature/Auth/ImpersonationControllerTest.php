<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;
use Tests\Traits\TestHelpers;

class ImpersonationControllerTest extends TestCase
{
    use RefreshDatabase, TestHelpers;

    /**
     * A super-admin can start an impersonation session and receives the
     * expected payload keys in the response.
     */
    public function test_start_impersonation_returns_token(): void
    {
        $this->setUpOrganization();

        $admin  = User::factory()->superAdmin()->create(['organization_id' => $this->organization->id]);
        $target = User::factory()->create(['organization_id' => $this->organization->id, 'is_super_admin' => false]);

        $token = JWTAuth::fromUser($admin);

        $response = $this->withToken($token)
            ->postJson("/api/v1/auth/impersonate/{$target->id}", [
                'reason' => 'Investigating reported invoice display issue for the user',
            ]);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'token',
                    'expires_at',
                    'impersonation_session_id',
                    'target_user' => ['id', 'name', 'email'],
                ],
            ]);
    }

    /**
     * The reason field is required; omitting it returns a 422 with a
     * validation error on the reason key.
     */
    public function test_start_impersonation_requires_reason(): void
    {
        $this->setUpOrganization();

        $admin  = User::factory()->superAdmin()->create(['organization_id' => $this->organization->id]);
        $target = User::factory()->create(['organization_id' => $this->organization->id, 'is_super_admin' => false]);

        $token = JWTAuth::fromUser($admin);

        $response = $this->withToken($token)
            ->postJson("/api/v1/auth/impersonate/{$target->id}", []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);
    }

    /**
     * Attempting to impersonate another super-admin must be rejected with a
     * 403 response.
     */
    public function test_cannot_impersonate_super_admin_via_endpoint(): void
    {
        $this->setUpOrganization();

        $admin  = User::factory()->superAdmin()->create(['organization_id' => $this->organization->id]);
        $target = User::factory()->superAdmin()->create(['organization_id' => $this->organization->id]);

        $token = JWTAuth::fromUser($admin);

        $response = $this->withToken($token)
            ->postJson("/api/v1/auth/impersonate/{$target->id}", [
                'reason' => 'Attempting to impersonate a super admin user',
            ]);

        $response->assertStatus(403);
    }

    /**
     * Calling POST /auth/impersonate/end with a normal (non-impersonation) JWT
     * — i.e. no impersonation attributes set — returns a 400 error.
     */
    public function test_end_impersonation_without_session_returns_400(): void
    {
        $this->setUpOrganization();

        $user  = User::factory()->create(['organization_id' => $this->organization->id]);
        $token = JWTAuth::fromUser($user);

        $response = $this->withToken($token)
            ->postJson('/api/v1/auth/impersonate/end');

        $response->assertStatus(400)
            ->assertJsonPath('error.code', 'NOT_IMPERSONATING');
    }
}
