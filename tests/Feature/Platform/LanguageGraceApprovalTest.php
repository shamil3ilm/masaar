<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\LanguageGrace;
use App\Domains\Platform\Approvals\Actions\LanguageGraceActions;
use App\Domains\Platform\Approvals\ApprovalPolicies;
use App\Domains\Platform\Models\ApprovalRequest;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Granting or extending a language grace is an admin action an approval
 * policy can hold, off as shipped like the other actions that change no
 * one's access. Withdrawing a grace is never held.
 */
class LanguageGraceApprovalTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private User $owner;

    private User $operations;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA', 'vat_number' => '300000000000003', 'status' => 'active']);
        $this->owner = $this->admin('owner', 'ops@example.test');
        $this->operations = $this->admin('operations', 'second@example.test');
    }

    public function test_it_ships_off(): void
    {
        $this->assertFalse(app(ApprovalPolicies::class)->get(LanguageGraceActions::GRANT)->enabled);

        $this->grant($this->owner)->assertSessionHas('success');

        $this->assertTrue(app(LanguageGrace::class)->active($this->acme));
    }

    public function test_a_held_grant_waits_for_approval(): void
    {
        $policies = app(ApprovalPolicies::class);
        $policies->save(LanguageGraceActions::GRANT, $policies->clean(LanguageGraceActions::GRANT, [
            'enabled' => '1', 'approvals_required' => 1, 'approver_roles' => ['owner', 'operations'], 'expires_after_hours' => 24,
        ]), (string) $this->owner->id);

        $this->grant($this->owner)->assertSessionHas('success');

        $request = ApprovalRequest::query()->where('action', LanguageGraceActions::GRANT)->sole();
        $this->assertSame(ApprovalRequest::PENDING, $request->status);
        $this->assertFalse(app(LanguageGrace::class)->active($this->acme));

        $this->actingAs($this->operations)->post(route('admin.approvals.approve', $request->id))->assertSessionHas('success');

        $this->assertTrue(app(LanguageGrace::class)->active($this->acme));
        // Who asked for it, not who approved it.
        $this->assertSame($this->owner->id, DB::table('language_graces')->where('org_id', $this->acme->id)->value('updated_by'));

        // Withdrawing it is never held.
        $this->actingAs($this->owner)
            ->post('/admin/organizations/'.$this->acme->id.'/language-grace', ['until' => '', 'reason' => 'Done'])
            ->assertSessionHas('success');
        $this->assertFalse(app(LanguageGrace::class)->active($this->acme));
    }

    private function grant(User $admin): TestResponse
    {
        return $this->actingAs($admin)->post('/admin/organizations/'.$this->acme->id.'/language-grace', [
            'until' => CarbonImmutable::now()->addMonth()->toDateString(),
            'reason' => 'Migrating its catalogue',
        ]);
    }

    private function admin(string $role, string $email): User
    {
        return User::factory()->create([
            'is_platform_admin' => true, 'platform_role' => $role, 'email' => $email,
            'two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now(),
        ]);
    }
}
