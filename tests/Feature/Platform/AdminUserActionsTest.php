<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\Auth\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Support helping someone back in: a reset link to their own address, and a
 * second factor cleared for someone who lost their phone.
 */
class AdminUserActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $support;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->support = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'support']);
        $this->customer = User::factory()->create(['email' => 'sara@acme.test']);
    }

    public function test_support_sends_a_reset_link(): void
    {
        $this->console()->post("/admin/users/{$this->customer->id}/password-reset")
            ->assertSessionHas('success');

        Notification::assertSentTo($this->customer, ResetPassword::class);
    }

    public function test_an_auditor_cannot_send_one(): void
    {
        $this->support->forceFill(['platform_role' => 'auditor'])->save();

        $this->console()->post("/admin/users/{$this->customer->id}/password-reset")->assertForbidden();

        Notification::assertNothingSent();
    }

    public function test_a_senior_admin_is_refused(): void
    {
        $owner = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'owner']);

        $this->console()->post("/admin/users/{$owner->id}/password-reset")->assertSessionHas('error');

        Notification::assertNothingSent();
    }

    public function test_a_members_two_factor_resets(): void
    {
        $this->customer->forceFill([
            'two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',
            'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->console()->post("/admin/users/{$this->customer->id}/reset-two-factor")
            ->assertSessionHas('success');

        $this->assertFalse($this->customer->fresh()->hasTwoFactor());
    }

    private function console(): static
    {
        $this->support->forceFill([
            'two_factor_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',
            'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $this->actingAs($this->support);
    }
}
