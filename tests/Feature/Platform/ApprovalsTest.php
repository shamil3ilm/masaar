<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Approvals\ApprovalPolicies;
use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;
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
 * with a reason when one rejects it.
 */
class ApprovalsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $second;

    private User $operations;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->owner = $this->admin('owner');
        $this->second = $this->admin('owner');
        $this->operations = $this->admin('operations');
    }

    public function test_admin_access_waits_for_another_owner(): void
    {
        $this->actingAs($this->owner)
            ->post("/admin/admins/{$this->operations->id}/role", ['role' => 'auditor', 'approval_reason' => 'Moving to compliance'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $request = ApprovalRequest::query()->sole();
        $this->assertSame(['admins.role', 'pending', 'Moving to compliance'], [$request->action, $request->status, $request->reason]);
        $this->assertSame('operations', $this->operations->fresh()->platform_role);
        $this->assertSame(['before' => ['role' => 'operations'], 'after' => ['role' => 'auditor']], $request->changes);

        $this->actingAs($this->second)->post(route('admin.approvals.approve', $request->id))->assertSessionHas('success');

        $this->assertSame('auditor', $this->operations->fresh()->platform_role);
        $this->assertSame([ApprovalRequest::EXECUTED, $this->second->id], [$request->fresh()->status, $request->fresh()->approved_by]);
    }

    public function test_nobody_approves_their_own_request(): void
    {
        $request = $this->heldRoleChange();

        $this->actingAs($this->owner)->post(route('admin.approvals.approve', $request->id))->assertSessionHas('error');

        $this->assertSame(ApprovalRequest::PENDING, $request->fresh()->status);
        $this->assertSame('operations', $this->operations->fresh()->platform_role);
    }

    public function test_a_rejection_keeps_its_reason(): void
    {
        $request = $this->heldRoleChange();

        $this->actingAs($this->second)->post(route('admin.approvals.reject', $request->id), ['reason' => 'Not agreed'])->assertSessionHas('success');

        $this->assertSame([ApprovalRequest::REJECTED, 'Not agreed', $this->second->id], [$request->fresh()->status, $request->fresh()->decision_note, $request->fresh()->rejected_by]);
        $this->assertSame($this->owner->id, $request->fresh()->created_by);
        $this->assertSame('operations', $this->operations->fresh()->platform_role);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'security.approval.rejected')->where('user_id', $this->second->id)->exists());
    }

    public function test_a_request_lapses_when_undecided(): void
    {
        $request = $this->heldRoleChange();

        $this->travel(ApprovalPolicies::DEFAULT_HOURS + 1)->hours();

        $this->actingAs($this->second)->post(route('admin.approvals.approve', $request->id))->assertSessionHas('error');
        $this->assertSame(ApprovalRequest::EXPIRED, $request->fresh()->status);
        $this->assertSame('operations', $this->operations->fresh()->platform_role);
    }

    public function test_a_reason_is_asked_for(): void
    {
        $this->actingAs($this->owner)
            ->post("/admin/admins/{$this->operations->id}/role", ['role' => 'auditor'])
            ->assertSessionHasErrors('approval_reason');

        $this->assertSame(0, ApprovalRequest::query()->count());
    }

    /**
     * One owner and nobody else who could approve: the action goes through,
     * and the record says why.
     */
    public function test_a_lone_owner_still_acts(): void
    {
        $this->second->forceFill(['is_platform_admin' => false, 'platform_role' => null])->save();
        $newcomer = User::factory()->create(['email' => 'new@masaar.test']);

        $this->actingAs($this->owner)->post('/admin/admins', ['email' => 'new@masaar.test', 'role' => 'support'])->assertSessionHas('success');

        $this->assertSame('support', $newcomer->fresh()->platform_role);
        $this->assertSame(0, ApprovalRequest::query()->count());
        $this->assertTrue(DB::table('audit_logs')->where('action', 'security.approval.bypassed')->exists());
    }

    /**
     * Suspending the only other approver does not open the door: they are
     * still counted, so the request waits.
     */
    public function test_a_suspended_approver_still_counts(): void
    {
        $this->second->forceFill(['status' => 'suspended'])->save();

        $this->heldRoleChange();

        $this->assertSame('operations', $this->operations->fresh()->platform_role);
    }

    public function test_nobody_decides_their_own_access(): void
    {
        $this->actingAs($this->owner)->post("/admin/admins/{$this->second->id}/role", ['role' => 'support', 'approval_reason' => 'Rotation']);
        $request = ApprovalRequest::query()->sole();

        $this->actingAs($this->second)->post(route('admin.approvals.approve', $request->id))->assertSessionHas('error');

        $this->assertSame('owner', $this->second->fresh()->platform_role);
    }

    public function test_an_approved_organization_gets_its_plan(): void
    {
        app(ApprovalPolicies::class)->save('organizations.approve', ['enabled' => true, 'approvals_required' => 1, 'approver_roles' => ['owner'], 'expires_after_hours' => 24], $this->owner->id);
        $pending = Organization::create(['name' => 'Acme', 'country' => 'SA', 'status' => Organization::STATUS_PENDING_REVIEW]);

        $this->actingAs($this->operations)
            ->post("/admin/organizations/{$pending->id}/approve", ['tier' => 'starter', 'approval_reason' => 'Documents checked'])
            ->assertSessionHas('success');
        $this->assertTrue($pending->fresh()->isPendingReview());

        $request = ApprovalRequest::query()->sole();
        $this->actingAs($this->owner)->post(route('admin.approvals.approve', $request->id))
            ->assertRedirectContains('/admin/licences/')
            ->assertSessionHas('api_secret');

        $this->assertSame(Organization::STATUS_ACTIVE, $pending->fresh()->status);
        $this->assertSame($this->operations->id, $pending->fresh()->reviewed_by);
    }

    /**
     * Once any policy is on, changing one - turning approvals off included -
     * waits for approval too.
     */
    public function test_policy_changes_need_approval_too(): void
    {
        $this->actingAs($this->owner)->post(route('admin.approval-policies.update', 'admins.role'), [
            'enabled' => '0', 'approvals_required' => '1', 'approver_roles' => ['owner'], 'expires_after_hours' => '72', 'approval_reason' => 'Too slow',
        ])->assertSessionHas('success');

        $this->assertTrue(app(ApprovalPolicies::class)->get('admins.role')->enabled);
        $request = ApprovalRequest::query()->sole();
        $this->assertSame(ApprovalPolicies::POLICIES, $request->action);

        $this->actingAs($this->second)->post(route('admin.approvals.approve', $request->id));
        $this->assertFalse(app(ApprovalPolicies::class)->get('admins.role')->enabled);
    }

    public function test_approvers_are_told_by_email(): void
    {
        app(Customizations::class)->set('notifications.approval_requests', Level::User, Scope::person($this->second->id), false, $this->second->id);
        $third = $this->admin('owner');

        $this->heldRoleChange();

        Mail::assertSent(ApprovalMail::class, fn (ApprovalMail $mail): bool => $mail->hasTo($third->email) && $mail->kind === ApprovalMail::REQUESTED);
        Mail::assertNotSent(ApprovalMail::class, fn (ApprovalMail $mail): bool => $mail->hasTo($this->second->email) || $mail->hasTo($this->owner->email));
    }

    public function test_the_screens_show_the_change(): void
    {
        $request = $this->heldRoleChange();
        $auditor = $this->admin('auditor');

        $this->actingAs($this->second)->get('/admin')->assertOk()->assertSee(route('admin.approvals.index'), false);
        $this->actingAs($this->second)->get(route('admin.approvals.index'))->assertOk()->assertSee($request->summary);
        $this->actingAs($this->second)->get(route('admin.approvals.show', $request->id))
            ->assertOk()->assertSee('operations')->assertSee('auditor')->assertSee(__('Reject'));
        $this->actingAs($auditor)->get(route('admin.approvals.show', $request->id))->assertOk()->assertDontSee(__('Your decision'));
        $this->actingAs($auditor)->get(route('admin.approval-policies.index'))->assertOk()->assertSee('admins.grant');
        $this->actingAs($auditor)->post(route('admin.approvals.approve', $request->id))->assertForbidden();
    }

    public function test_the_api_cannot_go_around(): void
    {
        app(ApprovalPolicies::class)->save('licences.issue', ['enabled' => true, 'approvals_required' => 1, 'approver_roles' => ['owner'], 'expires_after_hours' => 24], $this->owner->id);
        $organization = Organization::create(['name' => 'Acme', 'country' => 'SA']);

        $this->withToken(JWTAuth::fromUser($this->operations))->postJson('/api/admin/licenses', [
            'org_id' => $organization->id, 'organization_name' => 'Acme', 'contact_email' => 'a@acme.test', 'tier' => 'starter',
        ])->assertStatus(409);

        $this->assertSame(0, DB::table('licenses')->count());
    }

    private function heldRoleChange(): ApprovalRequest
    {
        $this->actingAs($this->owner)
            ->post("/admin/admins/{$this->operations->id}/role", ['role' => 'auditor', 'approval_reason' => 'Moving to compliance'])
            ->assertSessionHas('success');

        return ApprovalRequest::query()->latest()->firstOrFail();
    }

    private function admin(string $role): User
    {
        return User::factory()->create(['is_platform_admin' => true, 'platform_role' => $role]);
    }
}
