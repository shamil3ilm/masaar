<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Roles in an organization: changing one, the owner and accountant roles,
 * bringing back someone removed - and always an owner or an admin left.
 */
class MemberRolesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private User $owner;

    private User $admin;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme Trading', 'country' => 'SA']);
        $this->owner = $this->person('owner');
        $this->admin = $this->person('admin');
        $this->member = $this->person('member');
    }

    public function test_an_admin_changes_a_role(): void
    {
        $this->portal($this->admin)->post(route('portal.team.role', $this->member->id), ['role' => 'accountant'])->assertSessionHas('success');

        $this->assertSame('accountant', $this->member->fresh()->roleIn($this->acme->id));
        $this->assertTrue(DB::table('audit_logs')->where('action', 'security.member.role_changed')->where('user_id', $this->admin->id)->exists());

        $this->portal($this->admin)->post(route('portal.team.role', $this->admin->id), ['role' => 'member'])->assertSessionHas('error');
        $this->portal($this->member)->post(route('portal.team.role', $this->admin->id), ['role' => 'viewer'])->assertForbidden();
    }

    public function test_only_an_owner_gives_ownership(): void
    {
        $this->portal($this->admin)->post(route('portal.team.role', $this->member->id), ['role' => 'owner'])->assertSessionHas('error');
        $this->portal($this->admin)->post(route('portal.team.role', $this->owner->id), ['role' => 'member'])->assertSessionHas('error');
        $this->portal($this->admin)->post(route('portal.team.invite'), ['email' => 'new@acme.test', 'role' => 'owner'])->assertSessionHas('error');

        $this->portal($this->owner)->post(route('portal.team.role', $this->member->id), ['role' => 'owner'])->assertSessionHas('success');
        $this->assertSame('owner', $this->member->fresh()->roleIn($this->acme->id));
    }

    public function test_an_owner_or_admin_always_remains(): void
    {
        $solo = Organization::create(['name' => 'Solo', 'country' => 'SA']);
        $founder = User::factory()->create();
        $founder->organizations()->attach($solo->id, ['role' => 'owner', 'status' => 'active']);
        $helper = User::factory()->create();
        $helper->organizations()->attach($solo->id, ['role' => 'owner', 'status' => 'active']);

        $this->actingAs($helper)->withSession(['portal_org_id' => $solo->id])
            ->post(route('portal.team.role', $founder->id), ['role' => 'member'])->assertSessionHas('success');
        $this->actingAs($founder->fresh())->withSession(['portal_org_id' => $solo->id])
            ->post(route('portal.team.remove', $helper->id))->assertForbidden();

        $this->assertSame('owner', $helper->fresh()->roleIn($solo->id));
        $this->expectException(\DomainException::class);
        app(Team::class)->changeRole($solo->id, $helper->id, 'member', $founder->fresh());
    }

    public function test_a_removed_member_comes_back(): void
    {
        $this->portal($this->admin)->post(route('portal.team.remove', $this->member->id))->assertSessionHas('success');
        $this->assertNull($this->member->fresh()->roleIn($this->acme->id));

        $this->portal($this->admin)->get(route('portal.team.index'))->assertOk()->assertSee(__('Bring back'));
        $this->portal($this->admin)->post(route('portal.team.restore', $this->member->id))->assertSessionHas('success');

        $this->assertSame('member', $this->member->fresh()->roleIn($this->acme->id));
    }

    /**
     * An accountant reads, reports, exports and sees the plan's invoices,
     * and changes nothing.
     */
    public function test_an_accountant_reads_and_exports(): void
    {
        $accountant = $this->person('accountant');

        $this->portal($accountant)->get(route('portal.reports'))->assertOk();
        $this->portal($accountant)->get(route('portal.reports.invoices'))->assertOk();
        $this->portal($accountant)->get(route('portal.billing'))->assertOk();
        $this->portal($accountant)->get(route('portal.invoices.create'))->assertForbidden();
        $this->portal($accountant)->post(route('portal.invoices.store'), [])->assertForbidden();
        $this->portal($accountant)->get(route('portal.team.index'))->assertForbidden();
        $this->portal($this->member)->get(route('portal.billing'))->assertForbidden();
    }

    public function test_the_team_shows_last_activity(): void
    {
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => $this->acme->id,
            'user_id' => $this->member->id,
            'action' => 'Invoice.created',
            'entity_type' => 'Invoice',
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        $this->portal($this->owner)->get(route('portal.team.index'))
            ->assertOk()
            ->assertSee(now()->subDays(2)->diffForHumans())
            ->assertSee(__('Accountant'));
    }

    private function person(string $role): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }

    private function portal(User $user): static
    {
        return $this->actingAs($user)->withSession(['portal_org_id' => $this->acme->id]);
    }
}
