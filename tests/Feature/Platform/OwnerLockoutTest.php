<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\Auth\Models\User;
use App\Domains\Platform\Approvals\ApprovalPolicies;
use App\Domains\Platform\Mail\OwnerRecoveredMail;
use App\Domains\Platform\Models\ApprovalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A sole owner is not locked out by approvals: bypass is turned off only
 * while another admin could approve, and a server-side break-glass command
 * (masaar:owner-recover) recovers one who is locked out all the same - on
 * the security record, with the owner emailed.
 */
class OwnerLockoutTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->owner = $this->admin('owner');
    }

    public function test_bypass_stays_on_when_alone(): void
    {
        $this->actingAs($this->owner)->post(route('admin.approval-policies.bypass'), ['enabled' => '0'])
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'no other admin can approve'));

        $this->assertTrue($this->policies()->allowsBypass());
    }

    public function test_bypass_turns_off_with_an_approver(): void
    {
        $approver = $this->admin('operations');
        // A suspended admin cannot approve, so does not count.
        $this->admin('operations')->forceFill(['status' => 'suspended'])->save();

        $this->actingAs($this->owner)->post(route('admin.approval-policies.bypass'), ['enabled' => '0', 'approval_reason' => 'Stricter'])->assertSessionHas('success');
        $this->actingAs($approver)->post(route('admin.approvals.approve', ApprovalRequest::query()->sole()->id));

        $this->assertFalse($this->policies()->allowsBypass());
    }

    public function test_recovery_turns_bypass_back_on(): void
    {
        $this->policies()->save(ApprovalPolicies::BYPASS, app(ApprovalPolicies::class)->clean(ApprovalPolicies::BYPASS, ['enabled' => false]), (string) $this->owner->id);

        $this->artisan('masaar:owner-recover', ['--enable-bypass' => true, '--force' => true, '--reason' => 'Sole owner locked out'])->assertSuccessful();

        $this->assertTrue($this->policies()->allowsBypass());
        $entry = DB::table('audit_logs')->where('action', 'security.platform_owner.recovered')->sole();
        $this->assertSame('Sole owner locked out', json_decode((string) $entry->metadata, true)['reason']);
        Mail::assertSent(OwnerRecoveredMail::class, fn (OwnerRecoveredMail $mail): bool => $mail->hasTo($this->owner->email) && $mail->done === OwnerRecoveredMail::ENABLED_BYPASS);
    }

    public function test_recovery_approves_one_request(): void
    {
        $this->policies()->save(ApprovalPolicies::BYPASS, app(ApprovalPolicies::class)->clean(ApprovalPolicies::BYPASS, ['enabled' => false]), (string) $this->owner->id);
        User::factory()->create(['email' => 'new@masaar.test']);
        $this->actingAs($this->owner)->post('/admin/admins', ['email' => 'new@masaar.test', 'role' => 'support', 'approval_reason' => 'First hire']);
        $request = ApprovalRequest::query()->where('action', 'admins.grant')->sole();

        $this->artisan('masaar:owner-recover', ['--approve' => $request->id])
            ->expectsQuestion('Why is it needed? This goes on the security record and to the owner', 'Hiring the second admin')
            ->expectsConfirmation('Approved from the server: '.$request->summary.' Go ahead?', 'yes')
            ->assertSuccessful();

        $this->assertSame([ApprovalRequest::EXECUTED, null], [$request->fresh()->status, $request->fresh()->approved_by]);
        $this->assertTrue((bool) User::query()->where('email', 'new@masaar.test')->value('is_platform_admin'));
        $this->assertTrue(DB::table('audit_logs')->where('action', 'security.approval.approved_from_server')->exists());
    }

    public function test_declining_changes_nothing(): void
    {
        $this->policies()->save(ApprovalPolicies::BYPASS, app(ApprovalPolicies::class)->clean(ApprovalPolicies::BYPASS, ['enabled' => false]), (string) $this->owner->id);

        $this->artisan('masaar:owner-recover', ['--enable-bypass' => true, '--reason' => 'Testing'])
            ->expectsConfirmation('Allow bypass when no other approver exists: turned back on. Go ahead?', 'no')
            ->assertFailed();

        $this->assertFalse($this->policies()->allowsBypass());
        Mail::assertNothingSent();
    }

    public function test_force_needs_a_reason(): void
    {
        $this->policies()->save(ApprovalPolicies::BYPASS, app(ApprovalPolicies::class)->clean(ApprovalPolicies::BYPASS, ['enabled' => false]), (string) $this->owner->id);

        $this->artisan('masaar:owner-recover', ['--enable-bypass' => true, '--force' => true])->assertFailed();

        $this->assertFalse($this->policies()->allowsBypass());
    }

    public function test_recovery_refused_with_another_approver(): void
    {
        $this->policies()->save(ApprovalPolicies::BYPASS, app(ApprovalPolicies::class)->clean(ApprovalPolicies::BYPASS, ['enabled' => false]), (string) $this->owner->id);
        $this->admin('operations');

        $this->artisan('masaar:owner-recover', ['--enable-bypass' => true, '--force' => true, '--reason' => 'Testing'])->assertFailed();

        $this->assertFalse($this->policies()->allowsBypass());
        $this->assertFalse(DB::table('audit_logs')->where('action', 'security.platform_owner.recovered')->exists());
    }

    private function policies(): ApprovalPolicies
    {
        return app(ApprovalPolicies::class);
    }

    private function admin(string $role): User
    {
        return User::factory()->create(['is_platform_admin' => true, 'platform_role' => $role]);
    }
}
