<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domains\Auth\Enums\PlatformRole;
use App\Domains\Auth\Models\User;
use App\Domains\Auth\Services\AccountStatus;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * What each admin role may do.
 *
 * Being a platform admin was all or nothing. Owner does everything;
 * Operations runs the service; Support reads and resets a lost second
 * factor; Auditor reads, including the audit log.
 */
class AdminRolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_support_reads_but_does_not_approve(): void
    {
        $pending = Organization::create(['name' => 'Acme', 'country' => 'SA', 'status' => Organization::STATUS_PENDING_REVIEW]);
        $support = $this->admin(PlatformRole::Support);

        $this->actingAs($support)->get("/admin/organizations/{$pending->id}")
            ->assertOk()
            ->assertDontSee('Approve and assign plan');

        $this->actingAs($support)->post("/admin/organizations/{$pending->id}/approve", ['tier' => 'starter'])
            ->assertForbidden();

        $this->assertTrue($pending->fresh()->isPendingReview());
    }

    public function test_support_resets_a_second_factor(): void
    {
        $locked = $this->admin(PlatformRole::Auditor);
        $locked->forceFill(['two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'two_factor_confirmed_at' => now()])->save();

        $this->actingAs($this->admin(PlatformRole::Support))
            ->post("/admin/users/{$locked->id}/reset-two-factor")
            ->assertSessionHas('success');

        $this->assertFalse($locked->fresh()->hasTwoFactor());
    }

    public function test_auditors_read_the_audit_log(): void
    {
        $auditor = $this->admin(PlatformRole::Auditor);
        $someone = User::factory()->create();

        $this->actingAs($auditor)->get('/admin/audit')->assertOk();
        $this->actingAs($auditor)->post("/admin/users/{$someone->id}/suspend", ['reason' => 'test'])->assertForbidden();
        $this->actingAs($this->admin(PlatformRole::Support))->get('/admin/audit')->assertForbidden();
    }

    public function test_operations_cannot_configure_the_platform(): void
    {
        $operations = $this->admin(PlatformRole::Operations);

        $this->actingAs($operations)->get('/admin/kill-switches')->assertOk()->assertDontSee('kill-switches/enable', false);
        $this->actingAs($operations)->post('/admin/kill-switches/stop', ['reason' => 'test'])->assertForbidden();
        $this->actingAs($operations)->post('/admin/settings', [])->assertForbidden();
    }

    /**
     * The admin API is held to the same roles.
     */
    public function test_the_admin_api_follows_roles(): void
    {
        $token = JWTAuth::fromUser($this->admin(PlatformRole::Support));

        $this->withToken($token)->getJson('/api/admin/dashboard')->assertOk();
        $this->withToken($token)->postJson('/api/admin/dashboard/offline-queue/process')->assertForbidden();
    }

    public function test_an_owner_manages_admins(): void
    {
        $owner = $this->admin(PlatformRole::Owner);
        $newcomer = User::factory()->create(['email' => 'ops@masaar.test']);

        $this->actingAs($owner)->post('/admin/admins', ['email' => 'ops@masaar.test', 'role' => 'operations'])
            ->assertSessionHas('success')
            ->assertSessionHas('enrolment_link');

        $this->assertSame(PlatformRole::Operations, $newcomer->fresh()->platformRole());

        $this->actingAs($owner)->post("/admin/admins/{$newcomer->id}/role", ['role' => 'auditor'])->assertSessionHas('success');
        $this->assertSame(PlatformRole::Auditor, $newcomer->fresh()->platformRole());

        $this->actingAs($owner)->post("/admin/admins/{$newcomer->id}/revoke")->assertSessionHas('success');
        $this->assertNull($newcomer->fresh()->platformRole());
    }

    public function test_only_an_owner_sees_admins(): void
    {
        $operations = $this->admin(PlatformRole::Operations);

        $this->actingAs($operations)->get('/admin/admins')->assertForbidden();
        $this->actingAs($operations)->get('/admin')->assertOk()->assertDontSee(route('admin.admins.index'), false);
    }

    /**
     * Nobody changes their own access, and an owner always remains.
     */
    public function test_an_owner_always_remains(): void
    {
        $owner = $this->admin(PlatformRole::Owner);
        $second = $this->admin(PlatformRole::Owner);

        $this->actingAs($owner)->post("/admin/admins/{$owner->id}/role", ['role' => 'support'])->assertSessionHas('error');

        $this->actingAs($owner)->post("/admin/admins/{$second->id}/role", ['role' => 'support'])->assertSessionHas('success');

        $this->actingAs($second->fresh())->post("/admin/admins/{$owner->id}/revoke")->assertForbidden();
        $this->assertSame(PlatformRole::Owner, $owner->fresh()->platformRole());
    }

    /**
     * Support could reset an owner's second factor, take the enrolment link,
     * enrol their own authenticator on it and, with the owner's password,
     * become one. Access is changed only by an owner or a higher rank.
     */
    public function test_support_cannot_take_an_owner(): void
    {
        $owner = $this->admin(PlatformRole::Owner);
        $owner->forceFill(['two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'two_factor_confirmed_at' => now()])->save();
        $support = $this->admin(PlatformRole::Support);

        $this->actingAs($support)->post("/admin/users/{$owner->id}/reset-two-factor")->assertSessionHas('error');
        $this->assertTrue($owner->fresh()->hasTwoFactor());

        $this->actingAs($this->admin(PlatformRole::Operations))
            ->post("/admin/users/{$owner->id}/suspend", ['reason' => 'test'])
            ->assertSessionHas('error');
        $this->assertTrue($owner->fresh()->isActive());
    }

    public function test_the_last_owner_cannot_be_suspended(): void
    {
        $owner = $this->admin(PlatformRole::Owner);
        $other = $this->admin(PlatformRole::Owner);

        $this->actingAs($other)->post("/admin/users/{$owner->id}/suspend", ['reason' => 'test'])->assertSessionHas('success');
        $this->assertFalse($owner->fresh()->isActive());

        // $other is now the only active owner; nobody above can suspend them,
        // and an owner cannot suspend themselves.
        $this->assertSame('You cannot suspend your own account.', app(AccountStatus::class)->suspend($other->id, $other->id));
    }

    /**
     * Only a missing role means owner; a value that is no role grants nothing.
     */
    public function test_an_unknown_role_grants_nothing(): void
    {
        $odd = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'superuser']);

        $this->actingAs($odd)->get('/admin')->assertForbidden();
    }

    public function test_licence_audit_needs_audit_access(): void
    {
        $this->withToken(JWTAuth::fromUser($this->admin(PlatformRole::Support)))
            ->getJson('/api/admin/licenses/'.Str::uuid().'/audit')
            ->assertForbidden();
    }

    private function admin(PlatformRole $role): User
    {
        return User::factory()->create(['is_platform_admin' => true, 'platform_role' => $role->value]);
    }
}
