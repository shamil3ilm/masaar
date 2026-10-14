<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Auth\Models\User;
use App\Domains\Auth\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * A platform admin signs in with a second factor.
 *
 * A platform admin can stop filing for every tenant, suspend any account and
 * change the thresholds the platform runs on. A password was all of it - and
 * the API's login issued an admin token on the same password, so a second
 * factor only on the console would have been a door with a window beside it.
 */
class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_an_admin_is_asked_a_code(): void
    {
        $this->enrolledAdmin();

        $this->post('/login', ['email' => 'ops@masaar.test', 'password' => 'password'])
            ->assertRedirect(route('login.two-factor'));

        $this->assertGuest();
    }

    public function test_the_right_code_signs_in(): void
    {
        $admin = $this->enrolledAdmin();

        $this->post('/login', ['email' => 'ops@masaar.test', 'password' => 'password']);
        $this->post('/login/two-factor', ['code' => Totp::code(self::SECRET, time())])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_a_wrong_code_is_refused(): void
    {
        $this->enrolledAdmin();

        $this->post('/login', ['email' => 'ops@masaar.test', 'password' => 'password']);
        $this->post('/login/two-factor', ['code' => '000000'])->assertSessionHasErrors('code');

        $this->assertGuest();
        $this->assertSame(1, AuditLog::where('action', 'security.mfa.failed')->count());
    }

    /**
     * A code is good for its whole window; seen typed, it must not work again.
     */
    public function test_a_code_cannot_be_replayed(): void
    {
        $this->enrolledAdmin();
        $code = Totp::code(self::SECRET, time());

        $this->post('/login', ['email' => 'ops@masaar.test', 'password' => 'password']);
        $this->post('/login/two-factor', ['code' => $code]);
        $this->post('/logout');

        $this->post('/login', ['email' => 'ops@masaar.test', 'password' => 'password']);
        $this->post('/login/two-factor', ['code' => $code])->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_a_recovery_code_works_once(): void
    {
        $this->enrolledAdmin(['abcde-fghij', 'klmno-pqrst']);

        $this->post('/login', ['email' => 'ops@masaar.test', 'password' => 'password']);
        $this->post('/login/two-factor', ['code' => 'abcde-fghij'])->assertRedirect(route('admin.dashboard'));
        $this->post('/logout');

        $this->post('/login', ['email' => 'ops@masaar.test', 'password' => 'password']);
        $this->post('/login/two-factor', ['code' => 'abcde-fghij'])->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    /**
     * An admin without a second factor cannot reach the console until they
     * have one: enrolment is the next page, not an option.
     */
    public function test_an_unenrolled_admin_must_enrol(): void
    {
        $admin = User::factory()->create(['email' => 'new@masaar.test', 'is_platform_admin' => true]);

        $this->post('/login', ['email' => 'new@masaar.test', 'password' => 'password'])
            ->assertRedirect(route('login.two-factor.setup'));
        $this->assertGuest();

        $this->get('/login/two-factor/setup')->assertOk()->assertSee('<svg', false);

        $secret = (string) session('mfa.setup_secret');

        $this->post('/login/two-factor/setup', ['code' => Totp::code($secret, time())])
            ->assertOk()
            ->assertSee('recovery', false);

        $this->assertAuthenticatedAs($admin);
        $this->assertTrue($admin->fresh()->hasTwoFactor());
        $this->assertCount(8, (array) $admin->fresh()->two_factor_recovery_codes);
    }

    public function test_a_member_needs_no_code(): void
    {
        $member = User::factory()->create(['email' => 'clerk@acme.test']);

        $this->post('/login', ['email' => 'clerk@acme.test', 'password' => 'password']);

        $this->assertAuthenticatedAs($member);
    }

    public function test_the_api_wants_the_code_too(): void
    {
        $this->enrolledAdmin();

        $this->postJson('/api/auth/login', ['email' => 'ops@masaar.test', 'password' => 'password'])
            ->assertUnauthorized();

        $this->postJson('/api/auth/login', [
            'email' => 'ops@masaar.test',
            'password' => 'password',
            'two_factor_code' => Totp::code(self::SECRET, time()),
        ])->assertOk();
    }

    public function test_the_api_refuses_an_unenrolled_admin(): void
    {
        User::factory()->create(['email' => 'new@masaar.test', 'is_platform_admin' => true]);

        $this->postJson('/api/auth/login', ['email' => 'new@masaar.test', 'password' => 'password'])
            ->assertForbidden();
    }

    /**
     * An admin who has lost their phone is let back in by another admin.
     */
    public function test_another_admin_can_reset_it(): void
    {
        $locked = $this->enrolledAdmin();
        $helper = User::factory()->create(['is_platform_admin' => true]);

        $this->actingAs($helper)->post('/admin/users/'.$locked->id.'/reset-two-factor')
            ->assertSessionHas('success');

        $this->assertFalse($locked->fresh()->hasTwoFactor());
    }

    /**
     * @param  list<string>  $recoveryCodes
     */
    private function enrolledAdmin(array $recoveryCodes = []): User
    {
        $admin = User::factory()->create(['email' => 'ops@masaar.test', 'is_platform_admin' => true]);

        $admin->forceFill([
            'two_factor_secret' => self::SECRET,
            'two_factor_recovery_codes' => $recoveryCodes,
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $admin;
    }
}
