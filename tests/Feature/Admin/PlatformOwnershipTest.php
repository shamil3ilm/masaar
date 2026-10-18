<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domains\Auth\Models\User;
use App\Domains\Auth\Services\AdminInvitations;
use App\Domains\Auth\Services\AdminRoles;
use App\Domains\Auth\Services\PlatformAdmins;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The platform has exactly one owner. Ownership is offered by the owner and
 * accepted by the admin it is offered to, and only then does the old owner
 * step down, to the role they chose. Nothing else makes an owner.
 */
class PlatformOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $operations;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->owner = $this->admin('owner');
        $this->operations = $this->admin('operations');
    }

    public function test_ownership_moves_when_accepted(): void
    {
        $this->actingAs($this->owner)->post(route('admin.ownership.offer'), ['user_id' => $this->operations->id, 'demote_to' => 'support'])
            ->assertSessionHas('success');

        $this->assertSame('owner', $this->owner->fresh()->platform_role, 'nothing moves before they accept');
        $this->actingAs($this->operations)->get('/admin')->assertSee(__('Accept ownership'));

        $transfer = DB::table('owner_transfers')->sole();
        $this->actingAs($this->admin('operations'))->post(route('admin.ownership.accept', $transfer->id))->assertSessionHas('error');
        $this->actingAs($this->operations)->post(route('admin.ownership.accept', $transfer->id))->assertSessionHas('success');

        $this->assertSame(['owner', 'support'], [$this->operations->fresh()->platform_role, $this->owner->fresh()->platform_role]);
        $this->assertSame(1, User::query()->where('is_platform_admin', true)->where('platform_role', 'owner')->count());
        $this->assertTrue(DB::table('audit_logs')->where('action', 'security.platform_owner.transferred')->exists());
    }

    public function test_the_owner_steps_down_to_operations(): void
    {
        $this->actingAs($this->owner)->get(route('admin.admins.index'))->assertOk()->assertSee(__('Offer ownership'));

        $this->actingAs($this->owner)->post(route('admin.ownership.offer'), ['user_id' => $this->operations->id, 'demote_to' => 'operations']);
        $this->actingAs($this->operations)->post(route('admin.ownership.accept', DB::table('owner_transfers')->value('id')));

        $this->assertSame('operations', $this->owner->fresh()->platform_role);
        $this->actingAs($this->operations->fresh())->post(route('admin.ownership.offer'), ['user_id' => $this->operations->id, 'demote_to' => 'support'])->assertSessionHas('error');
    }

    public function test_a_role_change_never_makes_one(): void
    {
        $this->expectException(DomainException::class);

        app(PlatformAdmins::class)->changeRole($this->operations->id, 'owner', $this->owner);
    }

    public function test_a_grant_never_makes_one(): void
    {
        $someone = User::factory()->create(['email' => 'someone@masaar.test']);

        $this->actingAs($this->owner)->post('/admin/admins', ['email' => 'someone@masaar.test', 'role' => 'owner'])->assertSessionHas('error');

        $this->assertFalse($someone->fresh()->is_platform_admin);
    }

    public function test_an_invitation_never_makes_one(): void
    {
        $this->actingAs($this->owner)->post(route('admin.admins.invite'), ['email' => 'new@masaar.test', 'role' => 'owner'])->assertSessionHas('error');
        Mail::assertNothingSent();

        $this->expectException(DomainException::class);
        app(AdminInvitations::class)->invite('new@masaar.test', 'owner', $this->owner);
    }

    /**
     * Managing admins is the owner's alone, so no custom role is a second
     * owner.
     */
    public function test_a_custom_role_never_makes_one(): void
    {
        $this->expectException(ValidationException::class);

        app(AdminRoles::class)->clean(null, ['name' => 'Co-owner', 'rank' => 3, 'permissions' => ['console.view', 'admins.manage']]);
    }

    /**
     * More than one owner before the rule: the earliest active one stays,
     * the others become operations, each on the record.
     */
    public function test_extra_owners_are_demoted_once(): void
    {
        DB::table('users')->where('id', $this->owner->id)->update(['created_at' => now()->subYear()]);
        $later = $this->admin('owner');
        $unnamed = User::factory()->create(['is_platform_admin' => true, 'platform_role' => null]);

        (require database_path('migrations/0860_single_platform_owner.php'))->up();

        $this->assertSame('owner', $this->owner->fresh()->platform_role);
        $this->assertSame(['operations', 'operations'], [$later->fresh()->platform_role, $unnamed->fresh()->platform_role]);
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'security.platform_owner.demoted_to_single_owner')->count());
    }

    private function admin(string $role): User
    {
        return User::factory()->create([
            'is_platform_admin' => true,
            'platform_role' => $role,
            'two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
