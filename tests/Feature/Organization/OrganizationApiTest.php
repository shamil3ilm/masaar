<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Listing, reading, founding and amending organizations over the tenant API.
 */
class OrganizationApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private Organization $former;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        $this->former = Organization::create(['name' => 'Former Co', 'country' => 'SA']);

        $this->user = User::factory()->create();
        $this->user->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);
        $this->user->organizations()->attach($this->former->id, ['role' => 'member', 'status' => 'removed']);
    }

    /**
     * A removed membership grants nothing, including knowing the organization.
     */
    public function test_index_lists_active_memberships(): void
    {
        $response = $this->api()->getJson('/api/organizations')->assertOk();

        $this->assertSame([$this->acme->id], array_column($response->json('data.organizations'), 'id'));
    }

    public function test_removed_member_cannot_read_org(): void
    {
        $this->api()->getJson("/api/organizations/{$this->former->id}")->assertNotFound();
    }

    public function test_show_returns_own_org(): void
    {
        $this->api()->getJson("/api/organizations/{$this->acme->id}")
            ->assertOk()
            ->assertJsonPath('data.organization.id', $this->acme->id)
            ->assertJsonPath('data.organization.name', 'Acme');
    }

    public function test_store_founds_org_with_admin(): void
    {
        $response = $this->api()->postJson('/api/organizations', [
            'name' => 'Newco',
            'vat_number' => '311111111100003',
        ])
            ->assertCreated()
            ->assertJsonPath('message', 'Organization created')
            ->assertJsonPath('data.organization.name', 'Newco')
            ->assertJsonPath('data.organization.country', 'SA')
            ->assertJsonPath('data.organization.status', 'active')
            ->assertJsonPath('data.organization.compliance_profile', ['vat_number' => '311111111100003']);

        $id = $response->json('data.organization.id');

        $this->assertDatabaseHas('organization_user', [
            'user_id' => $this->user->id,
            'org_id' => $id,
            'role' => 'admin',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'Organization.created', 'entity_id' => $id]);
    }

    /**
     * An organization with no admin cannot be managed by anyone, so the
     * organization, its first membership and the audit entry stand or fall
     * together.
     */
    public function test_failed_audit_leaves_no_org(): void
    {
        $this->partialMock(AuditService::class, fn ($mock) => $mock
            ->shouldReceive('logCreated')
            ->andThrow(new RuntimeException('audit unavailable')));

        $this->api()->postJson('/api/organizations', ['name' => 'Newco'])->assertServerError();

        $this->assertDatabaseMissing('organizations', ['name' => 'Newco']);
    }

    public function test_update_renames_and_merges_vat(): void
    {
        $this->acme->update(['compliance_profile' => ['vat_number' => '300000000000003', 'regime' => 'standard']]);

        $this->api()->putJson("/api/organizations/{$this->acme->id}", [
            'name' => 'Acme Renamed',
            'vat_number' => '399999999900003',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Organization updated')
            ->assertJsonPath('data.organization.name', 'Acme Renamed')
            ->assertJsonPath('data.organization.compliance_profile', ['vat_number' => '399999999900003', 'regime' => 'standard']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'Organization.updated', 'entity_id' => $this->acme->id]);
    }

    public function test_blank_update_changes_nothing(): void
    {
        $this->acme->update(['compliance_profile' => ['vat_number' => '300000000000003']]);

        $this->api()->putJson("/api/organizations/{$this->acme->id}", ['vat_number' => ''])
            ->assertOk()
            ->assertJsonPath('data.organization.name', 'Acme')
            ->assertJsonPath('data.organization.compliance_profile', ['vat_number' => '300000000000003']);
    }

    /**
     * org.admin checks the role in the organization the token acts for. A
     * change to any other organization — one the caller is only a member of,
     * or was removed from — must not pass on the strength of that check.
     */
    public function test_cannot_change_another_org(): void
    {
        $other = Organization::create(['name' => 'Other Co', 'country' => 'SA']);
        $this->user->organizations()->attach($other->id, ['role' => 'member', 'status' => 'active']);

        $this->api()->putJson("/api/organizations/{$other->id}", ['name' => 'Hijacked'])->assertForbidden();
        $this->api()->putJson("/api/organizations/{$this->former->id}", ['name' => 'Hijacked'])->assertForbidden();

        $this->assertSame('Other Co', $other->fresh()->name);
        $this->assertSame('Former Co', $this->former->fresh()->name);
    }

    private function api(): self
    {
        return $this->withToken(
            JWTAuth::claims(['org_id' => $this->acme->id, 'role' => 'admin'])->fromUser($this->user)
        );
    }
}
