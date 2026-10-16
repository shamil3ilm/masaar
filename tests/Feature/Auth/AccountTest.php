<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Auth\Mail\EmailChangedMail;
use App\Domains\Auth\Mail\EmailChangeMail;
use App\Domains\Auth\Models\User;
use App\Domains\Auth\Support\Totp;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * My account: a person changing their own name, address, password and second
 * factor, without asking anyone.
 */
class AccountTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    private const NEW_PASSWORD = 'Another-pass-2026!';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Mail::fake();

        $this->user = User::factory()->create(['email' => 'sara@acme.test', 'name' => 'Sara']);
    }

    public function test_the_page_shows_the_account(): void
    {
        $this->actingAs($this->user)->get('/account')
            ->assertOk()
            ->assertSee('sara@acme.test')
            ->assertSee('Set up two-factor');
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get('/account')->assertRedirect(route('login'));
    }

    public function test_the_name_changes(): void
    {
        $this->actingAs($this->user)->post('/account/name', ['name' => 'Sara Ahmed'])
            ->assertSessionHas('success');

        $this->assertSame('Sara Ahmed', $this->user->fresh()->name);
    }

    /**
     * The address changes only once the new one proves it can receive mail,
     * so a typo cannot lock its owner out.
     */
    public function test_the_email_waits_for_the_link(): void
    {
        $this->actingAs($this->user)->post('/account/email', ['email' => 'sara@new.test', 'password' => 'password'])
            ->assertSessionHas('success');

        $this->assertSame('sara@acme.test', $this->user->fresh()->email);

        $url = null;
        Mail::assertSent(EmailChangeMail::class, function (EmailChangeMail $mail) use (&$url): bool {
            $url = $mail->confirmUrl;

            return $mail->hasTo('sara@new.test');
        });

        $this->actingAs($this->user)->get((string) $url)->assertRedirect(route('account.show'));

        $fresh = $this->user->fresh();
        $this->assertSame('sara@new.test', $fresh->email);
        $this->assertNull($fresh->pending_email);
        $this->assertNotNull($fresh->email_verified_at);
        Mail::assertSent(EmailChangedMail::class, fn (EmailChangedMail $mail): bool => $mail->hasTo('sara@acme.test'));
    }

    public function test_a_wrong_link_changes_nothing(): void
    {
        $this->actingAs($this->user)->post('/account/email', ['email' => 'sara@new.test', 'password' => 'password']);

        $this->actingAs($this->user)->get('/account/email/'.str_repeat('a', 48))
            ->assertSessionHas('error');

        $this->assertSame('sara@acme.test', $this->user->fresh()->email);
    }

    public function test_a_taken_email_is_refused(): void
    {
        User::factory()->create(['email' => 'taken@acme.test']);

        $this->actingAs($this->user)->post('/account/email', ['email' => 'taken@acme.test', 'password' => 'password'])
            ->assertSessionHas('error');

        Mail::assertNothingSent();
    }

    public function test_the_password_needs_the_current(): void
    {
        $this->actingAs($this->user)->post('/account/password', [
            'current_password' => 'wrong',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHas('error');

        $this->assertTrue(Hash::check('password', (string) $this->user->fresh()->password));
    }

    /**
     * A new password also ends API tokens issued before it.
     */
    public function test_the_password_changes(): void
    {
        $this->actingAs($this->user)->post('/account/password', [
            'current_password' => 'password',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHas('success');

        // This session survives its own password change.
        $this->get('/account')->assertOk();

        $fresh = $this->user->fresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, (string) $fresh->password));
        $this->assertNotNull($fresh->password_changed_at);
    }

    public function test_two_factor_turns_on(): void
    {
        $page = $this->actingAs($this->user);

        $page->post('/account/two-factor/start');
        $page->get('/account')->assertSee('<svg', false);

        $secret = (string) session('account.two_factor_secret');

        $page->post('/account/two-factor', ['code' => Totp::code($secret, time()), 'password' => 'password'])
            ->assertSessionHas('recovery_codes');

        $this->assertTrue($this->user->fresh()->hasTwoFactor());
    }

    public function test_a_wrong_setup_code_fails(): void
    {
        $page = $this->actingAs($this->user);

        $page->post('/account/two-factor/start');
        $page->post('/account/two-factor', ['code' => '000000', 'password' => 'password'])->assertSessionHas('error');

        $this->assertFalse($this->user->fresh()->hasTwoFactor());
    }

    /**
     * Turned on, it is asked for at the next sign-in - not just for admins.
     */
    public function test_a_member_with_it_is_asked(): void
    {
        $this->enrol($this->user);

        $this->post('/login', ['email' => 'sara@acme.test', 'password' => 'password'])
            ->assertRedirect(route('login.two-factor'));
        $this->assertGuest();

        $this->post('/login/two-factor', ['code' => Totp::code(self::SECRET, time())])
            ->assertRedirect(route('portal.dashboard'));
        $this->assertAuthenticatedAs($this->user);
    }

    public function test_the_api_asks_too(): void
    {
        $this->enrol($this->user);

        $this->postJson('/api/auth/login', ['email' => 'sara@acme.test', 'password' => 'password'])
            ->assertUnauthorized();

        $this->postJson('/api/auth/login', [
            'email' => 'sara@acme.test',
            'password' => 'password',
            'two_factor_code' => Totp::code(self::SECRET, time()),
        ])->assertOk();
    }

    public function test_two_factor_turns_off(): void
    {
        $this->enrol($this->user);

        $this->actingAs($this->user)->post('/account/two-factor/off', ['code' => Totp::code(self::SECRET, time())])
            ->assertSessionHas('success');

        $this->assertFalse($this->user->fresh()->hasTwoFactor());
        $this->assertSame(1, AuditLog::where('action', 'security.mfa.disabled')->count());
    }

    public function test_a_requiring_org_keeps_it_on(): void
    {
        $this->enrol($this->user);
        $acme = Organization::create(['name' => 'Acme Trading', 'country' => 'SA']);
        $acme->forceFill(['require_two_factor' => true])->save();
        $this->user->organizations()->attach($acme->id, ['role' => 'member', 'status' => 'active']);

        $this->actingAs($this->user)->post('/account/two-factor/off', ['code' => Totp::code(self::SECRET, time())])
            ->assertSessionHas('error');

        $this->assertTrue($this->user->fresh()->hasTwoFactor());
    }

    /**
     * Whoever holds a session is not necessarily its owner, and the address
     * is how an account is recovered.
     */
    public function test_an_email_change_needs_the_password(): void
    {
        $this->actingAs($this->user)->post('/account/email', ['email' => 'sara@new.test', 'password' => 'wrong'])
            ->assertSessionHas('error');

        $this->assertNull($this->user->fresh()->pending_email);
        Mail::assertNothingSent();
    }

    public function test_turning_on_needs_the_password(): void
    {
        $page = $this->actingAs($this->user);

        $page->post('/account/two-factor/start');
        $secret = (string) session('account.two_factor_secret');

        $page->post('/account/two-factor', ['code' => Totp::code($secret, time()), 'password' => 'wrong'])
            ->assertSessionHas('error');

        $this->assertFalse($this->user->fresh()->hasTwoFactor());
    }

    /**
     * The code that confirmed setup is spent: seen typed, it does not sign in.
     */
    public function test_the_setup_code_is_spent(): void
    {
        $page = $this->actingAs($this->user);

        $page->post('/account/two-factor/start');
        $secret = (string) session('account.two_factor_secret');
        $code = Totp::code($secret, time());
        $page->post('/account/two-factor', ['code' => $code, 'password' => 'password']);

        $this->postJson('/api/auth/login', [
            'email' => 'sara@acme.test',
            'password' => 'password',
            'two_factor_code' => $code,
        ])->assertUnauthorized();
    }

    public function test_a_password_change_drops_pending_email(): void
    {
        $this->actingAs($this->user)->post('/account/email', ['email' => 'sara@new.test', 'password' => 'password']);

        $this->actingAs($this->user)->post('/account/password', [
            'current_password' => 'password',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ]);

        $this->assertNull($this->user->fresh()->pending_email);
    }

    /**
     * The portal sends such a member to set it up; the API refuses them.
     */
    public function test_the_api_honours_the_requirement(): void
    {
        $acme = Organization::create(['name' => 'Acme Trading', 'country' => 'SA']);
        $acme->forceFill(['require_two_factor' => true])->save();
        $this->user->organizations()->attach($acme->id, ['role' => 'member', 'status' => 'active']);

        $token = JWTAuth::claims(['org_id' => $acme->id, 'role' => 'member'])->fromUser($this->user);

        $this->withToken($token)->getJson('/api/invoices')->assertForbidden();

        $this->enrol($this->user);
        $token = JWTAuth::claims(['org_id' => $acme->id, 'role' => 'member'])->fromUser($this->user);

        $this->withToken($token)->getJson('/api/invoices')->assertOk();
    }

    private function enrol(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => self::SECRET,
            'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
        ])->save();
    }
}
