<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domains\Auth\Mail\AdminInvitationMail;
use App\Domains\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Inviting a platform admin by email, account or not: invitation, the
 * account it makes, then the second factor before the console opens.
 */
class AdminInvitationsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->owner = User::factory()->create(['is_platform_admin' => true, 'platform_role' => 'owner']);
    }

    public function test_a_stranger_becomes_an_admin(): void
    {
        $url = $this->invite('ops@example.test', 'operations');

        $this->get($url)->assertOk()->assertSee(__('Create account and accept'));

        $this->post($url.'/join', [
            'name' => 'Omar',
            'password' => 'Correct-Horse-9-Battery',
            'password_confirmation' => 'Correct-Horse-9-Battery',
            'terms' => '1',
        ])->assertRedirectContains('/login/two-factor/enrol/');

        $omar = User::query()->where('email', 'ops@example.test')->sole();
        $this->assertSame('operations', $omar->platform_role);
        $this->assertTrue($omar->hasVerifiedEmail());
        $this->assertFalse($omar->hasTwoFactor());
        $this->get($url)->assertRedirect(route('login'));
    }

    public function test_an_account_accepts_signed_in(): void
    {
        $sara = User::factory()->create(['email' => 'sara@example.test']);
        $url = $this->invite('sara@example.test', 'support');

        $this->actingAs(User::factory()->create())->post($url.'/accept')->assertSessionHas('error');
        $this->actingAs($sara)->post($url.'/accept')->assertRedirectContains('/login/two-factor/enrol/');

        $this->assertSame('support', $sara->fresh()->platform_role);
    }

    public function test_the_admins_page_lists_everything(): void
    {
        $this->invite('later@example.test', 'auditor');

        $this->actingAs($this->owner)->get(route('admin.admins.index'))
            ->assertOk()
            ->assertSee($this->owner->email)
            ->assertSee(__('Last sign-in'))
            ->assertSee(__('Never'))
            ->assertSee('later@example.test');
    }

    private function invite(string $email, string $role): string
    {
        $this->actingAs($this->owner)->post(route('admin.admins.invite'), ['email' => $email, 'role' => $role])->assertSessionHas('success');

        $url = null;
        Mail::assertSent(AdminInvitationMail::class, function (AdminInvitationMail $mail) use ($email, &$url): bool {
            $url = $mail->acceptUrl;

            return $mail->hasTo($email);
        });

        auth()->logout();

        return (string) parse_url((string) $url, PHP_URL_PATH);
    }
}
