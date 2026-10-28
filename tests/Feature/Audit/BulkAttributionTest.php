<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Domains\Auth\Models\User;
use App\Domains\Auth\Services\AccountDeletion;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Models\OrganizationInvitation;
use App\Domains\Organization\Services\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A change made with one query rather than through a model still names who
 * made it: ending, restoring or re-roling a membership, an account deleting
 * itself, and an invitation withdrawn or replaced.
 */
class BulkAttributionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private User $owner;

    private User $member;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        $this->owner = $this->memberOf('owner');
        $this->member = $this->memberOf('member');
        $this->team = app(Team::class);
    }

    public function test_a_removal_names_who_removed(): void
    {
        $this->team->remove($this->acme->id, $this->member->id, $this->owner);

        $this->assertSame([$this->owner->id, 'removed'], $this->membership($this->member));
    }

    public function test_a_restore_names_who_restored(): void
    {
        $this->team->remove($this->acme->id, $this->member->id, $this->memberOf('admin'));
        $this->team->restore($this->acme->id, $this->member->id, $this->owner);

        $this->assertSame([$this->owner->id, 'active'], $this->membership($this->member));
    }

    public function test_a_role_change_names_who(): void
    {
        $this->team->changeRole($this->acme->id, $this->member->id, 'accountant', $this->owner);

        $this->assertSame($this->owner->id, $this->membership($this->member)[0]);
    }

    public function test_a_deleted_account_ends_its_memberships(): void
    {
        app(AccountDeletion::class)->delete($this->member, 'password');

        $this->assertSame([$this->member->id, 'removed'], $this->membership($this->member));
    }

    public function test_a_revoked_invitation_names_who(): void
    {
        $this->actingAs($this->owner);
        $invitation = $this->team->invite($this->acme, 'new@acme.test', 'member', $this->owner);

        $this->team->revoke($this->acme->id, $invitation->id);

        $this->assertSoftDeleted('organization_invitations', ['id' => $invitation->id, 'deleted_by' => $this->owner->id]);
    }

    public function test_a_replaced_invitation_names_who(): void
    {
        $this->actingAs($this->owner);
        $first = $this->team->invite($this->acme, 'new@acme.test', 'member', $this->owner);

        $this->team->invite($this->acme, 'new@acme.test', 'viewer', $this->owner);

        $this->assertSoftDeleted('organization_invitations', ['id' => $first->id, 'deleted_by' => $this->owner->id]);
        $this->assertSame(1, OrganizationInvitation::acrossTenants()->count());
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private function membership(User $user): array
    {
        $row = DB::table('organization_user')->where('org_id', $this->acme->id)->where('user_id', $user->id)->first();

        return [$row->updated_by, (string) $row->status];
    }

    private function memberOf(string $role): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }
}
