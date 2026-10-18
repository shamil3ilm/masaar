<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Approvals\ApprovalPolicies;
use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;
use App\Domains\Platform\Mail\ApprovalBypassedMail;
use App\Domains\Platform\Mail\ApprovalMail;
use App\Domains\Platform\Models\ApprovalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Maker-checker for admin actions: an action its policy holds back becomes a
 * request, runs exactly as asked once another admin approves it, and ends
 * with a reason when one rejects it. With nobody else who could ever
 * approve, it goes through - logged, and the owner emailed - unless the
 * owner turned that off.
 */
class ApprovalsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $first;

    private User $second;

    private User $support;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->owner = $this->admin('owner');
        $this->first = $this->admin('operations');
        $this->second = $this->admin('operations');
        $this->support = $this->admin('support');
    }

    public function test_admin_access_waits_for_another_admin(): void
    {
        $this->actingAs($this->owner)
            ->post("/admin/admins/{$this->support->id}/role", ['role' => 'auditor', 'approval_reason' => 'Moving to compliance'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $request = ApprovalRequest::query()->sole();
        $this->assertSame(['admins.role', 'pending', 'Moving to compliance'], [$request->action, $request->status, $request->reason]);
        $this->assertSame('support', $this->support->fresh()->platform_role);
        $this->assertSame(['before' => ['role' => 'support'], 'after' => ['role' => 'auditor']], $request->changes);

        $this->actingAs($this->first)->post(route('admin.approvals.approve', $request->id))->assertSessionHas('success');

        $this->assertSame('auditor', $this->support->fresh()->platform_role);
        $this->assertSame([ApprovalRequest::EXECUTED, $this->first->id], [$request->fresh()->status, $request->fresh()->approved_by]);
    }

    public function test_nobody_approves_their_own_request(): void
    {
        $request = $this->heldRoleChange();

        $this->actingAs($this->owner)->post(route('admin.approvals.approve', $request->id))->assertSessionHas('error');

        $this->assertSame(ApprovalRequest::PENDING, $request->fresh()->status);
        $this->assertSame('support', $this->support->fresh()->platform_role);
    }

    public function test_a_rejection_keeps_its_reason(): void
    {
        $request = $this->heldRoleChange();

        $this->actingAs($this->first)->post(route('admin.approvals.reject', $request->id), ['reason' => 'Not agreed'])->assertSessionHas('success');

        $this->assertSame([ApprovalRequest::REJECTED, 'Not agreed', $this->first->id], [$request->fresh()->status, $request->fresh()->decision_note, $request->fresh()->rejected_by]);
        $this->assertSame($this->owner->id, $request->fresh()->created_by);
        $this->assertSame('support', $this->support->fresh()->platform_role);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'security.approval.rejected')->where('user_id', $this->first->id)->exists());
    }

    public function test_a_request_lapses_when_undecided(): void
    {
        $request = $this->heldRoleChange();

        $this->travel(ApprovalPolicies::DEFAULT_HOURS + 1)->hours();

        $this->actingAs($this->first)->post(route('admin.approvals.approve', $request->id))->assertSessionHas('error');
        $this->assertSame(ApprovalRequest::EXPIRED, $request->fresh()->status);
        $this->assertSame('support', $this->support->fresh()->platform_role);
    }

    public function test_a_reason_is_asked_for(): void
    {
        $this->actingAs($this->owner)
            ->post("/admin/admins/{$this->support->id}/role", ['role' => 'auditor'])
            ->assertSessionHasErrors('approval_reason');

        $this->assertSame(0, ApprovalRequest::query()->count());
    }

    /**
     * Nobody else who could ever approve: the action goes through, the
     * record says why, and the owner is told what changed, by whom and why.
     */
    public function test_a_lone_owner_still_acts(): void
    {
        $this->alone();
        $newcomer = User::factory()->create(['email' => 'new@masaar.test']);

        $this->actingAs($this->owner)->post('/admin/admins', ['email' => 'new@masaar.test', 'role' => 'support', 'approval_reason' => 'First hire'])->assertSessionHas('success');

        $this->assertSame('support', $newcomer->fresh()->platform_role);
        $this->assertSame(0, ApprovalRequest::query()->count());
        $entry = DB::table('audit_logs')->where('action', 'security.approval.bypassed')->sole();
        $this->assertSame('bypassed: no other approver exists', json_decode((string) $entry->metadata, true)['why']);
        Mail::assertSent(ApprovalBypassedMail::class, fn (ApprovalBypassedMail $mail): bool => $mail->hasTo($this->owner->email)
            && $mail->reason === 'First hire' && str_contains($mail->requester, $this->owner->email) && $mail->after['role'] === 'support');
    }

    /**
     * With bypass off, a request nobody else could approve waits like any
     * other.
     */
    public function test_bypass_off_makes_it_wait(): void
    {
        $this->alone();

        $this->actingAs($this->owner)->post(route('admin.approval-policies.bypass'), ['enabled' => '0'])->assertSessionHas('success');
        $this->assertFalse(app(ApprovalPolicies::class)->allowsBypass());

        User::factory()->create(['email' => 'new@masaar.test']);
        $this->actingAs($this->owner)->post('/admin/admins', ['email' => 'new@masaar.test', 'role' => 'support', 'approval_reason' => 'First hire']);

        $this->assertSame(ApprovalRequest::PENDING, ApprovalRequest::query()->where('action', 'admins.grant')->sole()->status);
        $this->assertFalse((bool) User::query()->where('email', 'new@masaar.test')->value('is_platform_admin'));
    }

    /**
     * Whether bypass is allowed is the owner's alone, and under approval
     * once someone else can approve.
     */
    public function test_only_the_owner_sets_bypass(): void
    {
        $this->actingAs($this->first)->post(route('admin.approval-policies.bypass'), ['enabled' => '0'])->assertForbidden();

        $this->actingAs($this->owner)->post(route('admin.approval-policies.bypass'), ['enabled' => '0', 'approval_reason' => 'Stricter'])->assertSessionHas('success');
        $this->assertTrue(app(ApprovalPolicies::class)->allowsBypass(), 'it waits for approval');

        $this->actingAs($this->first)->post(route('admin.approvals.approve', ApprovalRequest::query()->sole()->id));
        $this->assertFalse(app(ApprovalPolicies::class)->allowsBypass());
    }

    /**
     * Suspending the only other approver does not open the door: they are
     * still counted, so the request waits.
     */
    public function test_a_suspended_approver_still_counts(): void
    {
        $this->second->forceFill(['is_platform_admin' => false, 'platform_role' => null])->save();
        $this->first->forceFill(['status' => 'suspended'])->save();

        $this->heldRoleChange();

        $this->assertSame('support', $this->support->fresh()->platform_role);
        Mail::assertNotSent(ApprovalBypassedMail::class);
    }

    public function test_nobody_decides_their_own_access(): void
    {
        $this->actingAs($this->owner)->post("/admin/admins/{$this->first->id}/role", ['role' => 'support', 'approval_reason' => 'Rotation']);
        $request = ApprovalRequest::query()->sole();

        $this->actingAs($this->first)->post(route('admin.approvals.approve', $request->id))->assertSessionHas('error');
        $this->assertSame('operations', $this->first->fresh()->platform_role);

        $this->actingAs($this->second)->post(route('admin.approvals.approve', $request->id))->assertSessionHas('success');
        $this->assertSame('support', $this->first->fresh()->platform_role);
    }

    public function test_an_approved_organization_gets_its_plan(): void
    {
        app(ApprovalPolicies::class)->save('organizations.approve', ['enabled' => true, 'approvals_required' => 1, 'approver_roles' => ['owner'], 'expires_after_hours' => 24], $this->owner->id);
        $pending = Organization::create(['name' => 'Acme', 'country' => 'SA', 'status' => Organization::STATUS_PENDING_REVIEW]);

        $this->actingAs($this->first)
            ->post("/admin/organizations/{$pending->id}/approve", ['tier' => 'starter', 'approval_reason' => 'Documents checked'])
            ->assertSessionHas('success');
        $this->assertTrue($pending->fresh()->isPendingReview());

        $request = ApprovalRequest::query()->sole();
        $this->actingAs($this->owner)->post(route('admin.approvals.approve', $request->id))
            ->assertRedirectContains('/admin/licences/')
            ->assertSessionHas('api_secret');

        $this->assertSame(Organization::STATUS_ACTIVE, $pending->fresh()->status);
        $this->assertSame($this->first->id, $pending->fresh()->reviewed_by);
    }

    /**
     * Once any policy is on, changing one - turning approvals off included -
     * waits for approval too.
     */
    public function test_policy_changes_need_approval_too(): void
    {
        $this->actingAs($this->owner)->post(route('admin.approval-policies.update', 'admins.role'), [
            'enabled' => '0', 'approvals_required' => '1', 'approver_roles' => ['owner', 'operations'], 'expires_after_hours' => '72', 'approval_reason' => 'Too slow',
        ])->assertSessionHas('success');

        $this->assertTrue(app(ApprovalPolicies::class)->get('admins.role')->enabled);
        $request = ApprovalRequest::query()->sole();
        $this->assertSame(ApprovalPolicies::POLICIES, $request->action);

        $this->actingAs($this->first)->post(route('admin.approvals.approve', $request->id));
        $this->assertFalse(app(ApprovalPolicies::class)->get('admins.role')->enabled);
    }

    public function test_approvers_are_told_by_email(): void
    {
        app(Customizations::class)->set('notifications.approval_requests', Level::User, Scope::person($this->second->id), false, $this->second->id);

        $this->heldRoleChange();

        Mail::assertSent(ApprovalMail::class, fn (ApprovalMail $mail): bool => $mail->hasTo($this->first->email) && $mail->kind === ApprovalMail::REQUESTED);
        Mail::assertNotSent(ApprovalMail::class, fn (ApprovalMail $mail): bool => $mail->hasTo($this->second->email) || $mail->hasTo($this->owner->email));
    }

    public function test_the_screens_show_the_change(): void
    {
        $request = $this->heldRoleChange();
        $auditor = $this->admin('auditor');

        $this->actingAs($this->first)->get('/admin')->assertOk()->assertSee(route('admin.approvals.index'), false);
        $this->actingAs($this->first)->get(route('admin.approvals.index'))->assertOk()->assertSee($request->summary);
        $this->actingAs($this->first)->get(route('admin.approvals.show', $request->id))
            ->assertOk()->assertSee('support')->assertSee('auditor')->assertSee(__('Reject'));
        $this->actingAs($auditor)->get(route('admin.approvals.show', $request->id))->assertOk()->assertDontSee(__('Your decision'));
        $this->actingAs($auditor)->get(route('admin.approval-policies.index'))->assertOk()->assertSee('admins.grant')->assertSee(__('Allow bypass when no other approver exists'));
        $this->actingAs($auditor)->post(route('admin.approvals.approve', $request->id))->assertForbidden();
    }

    public function test_the_api_cannot_go_around(): void
    {
        app(ApprovalPolicies::class)->save('licences.issue', ['enabled' => true, 'approvals_required' => 1, 'approver_roles' => ['owner'], 'expires_after_hours' => 24], $this->owner->id);
        $organization = Organization::create(['name' => 'Acme', 'country' => 'SA']);

        $this->withToken(JWTAuth::fromUser($this->first))->postJson('/api/admin/licenses', [
            'org_id' => $organization->id, 'organization_name' => 'Acme', 'contact_email' => 'a@acme.test', 'tier' => 'starter',
        ])->assertStatus(409);

        $this->assertSame(0, DB::table('licenses')->count());
    }

    /** Only the owner left: nobody else could ever approve. */
    private function alone(): void
    {
        foreach ([$this->first, $this->second, $this->support] as $admin) {
            $admin->forceFill(['is_platform_admin' => false, 'platform_role' => null])->save();
        }
    }

    private function heldRoleChange(): ApprovalRequest
    {
        $this->actingAs($this->owner)
            ->post("/admin/admins/{$this->support->id}/role", ['role' => 'auditor', 'approval_reason' => 'Moving to compliance'])
            ->assertSessionHas('success');

        return ApprovalRequest::query()->latest()->firstOrFail();
    }

    private function admin(string $role): User
    {
        return User::factory()->create(['is_platform_admin' => true, 'platform_role' => $role]);
    }
}
