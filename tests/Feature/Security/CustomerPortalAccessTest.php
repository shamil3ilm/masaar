<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domains\Organization\Models\Organization;
use App\Domains\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Regression guard for C-2 — the /portal surface was unauthenticated and read
 * its tenant from `?org_id=`, so any UUID returned that tenant's invoice
 * volumes, submission states and certificate status.
 *
 * The invariant under test: tenant identity is derived from the credential,
 * never from request input.
 */
class CustomerPortalAccessTest extends TestCase
{
    use RefreshDatabase;

    public static function portalRouteProvider(): array
    {
        return [
            'dashboard' => ['/portal'],
            'submissions' => ['/portal/submissions'],
            'certificates' => ['/portal/certificates'],
            'user activity' => ['/portal/users/00000000-0000-0000-0000-000000000000/activity'],
        ];
    }

    #[DataProvider('portalRouteProvider')]
    public function test_guest_redirected(string $uri): void
    {
        $this->get($uri)->assertRedirect('/login');
    }

    #[DataProvider('portalRouteProvider')]
    public function test_guest_cannot_use_org_id(string $uri): void
    {
        $organization = $this->makeOrganization('Victim Co');

        $this->get($uri.'?org_id='.$organization->id)->assertRedirect('/login');
    }

    #[DataProvider('portalRouteProvider')]
    public function test_cross_tenant_denied(string $uri): void
    {
        $attacker = $this->makeMember('Attacker Co');
        $victim = $this->makeOrganization('Victim Co');

        $this->actingAs($attacker)
            ->get($uri.'?org_id='.$victim->id)
            ->assertForbidden();
    }

    public function test_rejected_org_id_not_stored(): void
    {
        $attacker = $this->makeMember('Attacker Co');
        $victim = $this->makeOrganization('Victim Co');

        $this->actingAs($attacker)
            ->get('/portal?org_id='.$victim->id)
            ->assertForbidden()
            ->assertSessionMissing('portal_organization_id');
    }

    public function test_single_org_auto_selected(): void
    {
        $user = $this->makeMember('Acme');

        $this->actingAs($user)->get('/portal')->assertOk();
    }

    public function test_member_can_switch_org(): void
    {
        $user = User::factory()->create();
        $first = $this->makeOrganization('First Co');
        $second = $this->makeOrganization('Second Co');
        $user->organizations()->attach($first->id, ['role' => 'member', 'status' => 'active']);
        $user->organizations()->attach($second->id, ['role' => 'member', 'status' => 'active']);

        $this->actingAs($user)
            ->get('/portal?org_id='.$second->id)
            ->assertOk()
            ->assertSessionHas('portal_organization_id', $second->id);
    }

    /**
     * A revoked membership must stop granting access, including when the old
     * selection is still sitting in the session.
     */
    public function test_removed_member_denied(): void
    {
        $user = $this->makeMember('Acme');
        $organizationId = $user->organizations()->first()->id;

        $this->actingAs($user)->get('/portal')->assertOk();

        $user->organizations()->updateExistingPivot($organizationId, ['status' => 'removed']);

        $this->actingAs($user)
            ->withSession(['portal_organization_id' => $organizationId])
            ->get('/portal')
            ->assertForbidden();
    }

    /**
     * The selection screen must offer only the user's own organizations —
     * it previously queried the whole organizations table from the template.
     */
    public function test_picker_shows_own_orgs_only(): void
    {
        $user = User::factory()->create();
        $mine = $this->makeOrganization('My Own Company');
        $theirs = $this->makeOrganization('Someone Elses Company');
        $user->organizations()->attach($mine->id, ['role' => 'member', 'status' => 'active']);
        $user->organizations()->attach($this->makeOrganization('My Second Company')->id, [
            'role' => 'member', 'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get('/portal/switch')
            ->assertOk()
            ->assertSee('My Own Company')
            ->assertDontSee('Someone Elses Company');

        $this->assertNotNull($theirs->id);
    }

    private function makeOrganization(string $name): Organization
    {
        return Organization::create(['name' => $name, 'country' => 'SA']);
    }

    private function makeMember(string $organizationName): User
    {
        $user = User::factory()->create();
        $organization = $this->makeOrganization($organizationName);
        $user->organizations()->attach($organization->id, ['role' => 'member', 'status' => 'active']);

        return $user;
    }
}
