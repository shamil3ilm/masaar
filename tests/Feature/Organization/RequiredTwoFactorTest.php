<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An organization that requires a second factor gets one from everyone in it.
 */
class RequiredTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private User $admin;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme Trading', 'country' => 'SA']);
        $this->admin = User::factory()->create();
        $this->admin->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);
        $this->member = User::factory()->create();
        $this->member->organizations()->attach($this->acme->id, ['role' => 'member', 'status' => 'active']);
    }

    public function test_the_admin_needs_it_first(): void
    {
        $this->portal($this->admin)->post('/portal/company/security', ['require_two_factor' => '1'])
            ->assertSessionHas('error');

        $this->assertFalse($this->acme->fresh()->require_two_factor);
    }

    public function test_an_admin_turns_it_on(): void
    {
        $this->enrol($this->admin);

        $this->portal($this->admin)->post('/portal/company/security', ['require_two_factor' => '1'])
            ->assertSessionHas('success');

        $this->assertTrue($this->acme->fresh()->require_two_factor);
    }

    public function test_a_member_cannot_change_it(): void
    {
        $this->enrol($this->member);

        $this->portal($this->member)->post('/portal/company/security', ['require_two_factor' => '1'])
            ->assertForbidden();

        $this->assertFalse($this->acme->fresh()->require_two_factor);
    }

    public function test_a_member_without_it_is_sent(): void
    {
        $this->acme->forceFill(['require_two_factor' => true])->save();

        $this->portal($this->member)->get('/portal')
            ->assertRedirect(route('account.show').'#two-factor');
    }

    public function test_a_member_with_it_passes(): void
    {
        $this->acme->forceFill(['require_two_factor' => true])->save();
        $this->enrol($this->member);

        $this->portal($this->member)->get('/portal')->assertOk();
    }

    private function portal(User $user): static
    {
        return $this->actingAs($user)->withSession(['portal_org_id' => $this->acme->id]);
    }

    private function enrol(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',
            'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
        ])->save();
    }
}
