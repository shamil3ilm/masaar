<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domains\Auth\Http\Controllers\TwoFactorController;
use App\Domains\Auth\Models\User;
use App\Domains\Auth\Services\EnrolmentLinks;
use App\Domains\Auth\Services\TwoFactor;
use App\Domains\Platform\Http\Controllers\AdminUserController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Enrolling a second factor takes a link, not just the password.
 *
 * An unenrolled platform admin who signed in was sent straight to enrolment,
 * so whoever held the password - before the admin's first sign-in, or after
 * another admin reset theirs - could bind their own authenticator and keep
 * the account. Enrolment now needs a one-time link that only another admin,
 * or someone at the server's console, can issue.
 */
class EnrolmentLinkTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_a_password_alone_cannot_enrol(): void
    {
        $this->admin('new@masaar.test');

        $this->post('/login', ['email' => 'new@masaar.test', 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->get('/login/two-factor/setup')->assertRedirect(route('login'));
    }

    /**
     * A link permits only the admin it was issued to.
     */
    public function test_a_link_is_for_one_admin(): void
    {
        $intended = $this->admin('new@masaar.test');
        $this->admin('other@masaar.test');

        $this->get($this->links()->issue($intended, null));

        $this->post('/login', ['email' => 'other@masaar.test', 'password' => 'password'])
            ->assertSessionHasErrors('email');
    }

    public function test_an_expired_link_is_refused(): void
    {
        $admin = $this->admin('new@masaar.test');
        $link = $this->links()->issue($admin, null);

        $this->travel(EnrolmentLinks::VALID_HOURS + 1)->hours();

        $this->get($link)->assertSessionHasErrors('email');
        $this->assertNull(session(TwoFactorController::ENROLMENT));
    }

    /**
     * Spent by the enrolment it permits, in one conditional write: of two
     * browsers holding the same link, one enrols.
     */
    public function test_a_link_works_only_once(): void
    {
        $admin = $this->admin('new@masaar.test');
        $hash = EnrolmentLinks::hash($this->token($this->links()->issue($admin, null)));

        $this->assertTrue($this->links()->claim($admin, $hash));
        $this->assertFalse($this->links()->claim($admin, $hash));
    }

    /**
     * A mistyped code is not the end of the link: the claim rolls back.
     */
    public function test_a_wrong_code_keeps_the_link(): void
    {
        $admin = $this->admin('new@masaar.test');
        $this->get($this->links()->issue($admin, null));
        $this->post('/login', ['email' => 'new@masaar.test', 'password' => 'password']);
        $this->get('/login/two-factor/setup');

        $this->post('/login/two-factor/setup', ['code' => '000000'])->assertSessionHasErrors('code');

        $this->assertNotNull($admin->fresh()->two_factor_enrolment_hash);
        $this->assertFalse($admin->fresh()->hasTwoFactor());
    }

    public function test_a_reset_ends_an_earlier_link(): void
    {
        $admin = $this->admin('new@masaar.test');
        $link = $this->links()->issue($admin, null);

        app(TwoFactor::class)->reset($admin);

        $this->assertNull($this->links()->holder($this->token($link)));
    }

    public function test_issuing_again_replaces_the_link(): void
    {
        $admin = $this->admin('new@masaar.test');
        $first = $this->links()->issue($admin, null);
        $this->links()->issue($admin, null);

        $this->assertNull($this->links()->holder($this->token($first)));
    }

    /**
     * After a reset they need a link to get back in, so the reset comes with
     * one, shown once to the admin who did it.
     */
    public function test_a_reset_comes_with_a_link(): void
    {
        $locked = $this->admin('lost@masaar.test');
        $locked->forceFill(['two_factor_secret' => self::SECRET, 'two_factor_confirmed_at' => now()])->save();
        $helper = $this->admin('helper@masaar.test');

        $this->actingAs($helper)->post('/admin/users/'.$locked->id.'/reset-two-factor')
            ->assertSessionHas(AdminUserController::LINK, fn (string $link) => str_contains($link, '/login/two-factor/enrol/'));

        $this->assertNotNull($locked->fresh()->two_factor_enrolment_hash);
    }

    public function test_an_admin_cannot_link_themselves(): void
    {
        $admin = $this->admin('self@masaar.test');

        $this->actingAs($admin)->post('/admin/users/'.$admin->id.'/enrolment-link')
            ->assertSessionHas('error')
            ->assertSessionMissing(AdminUserController::LINK);
    }

    /**
     * The first admin has nobody to ask, so the server's console can issue
     * one - and reset a sole admin who has lost their phone.
     */
    public function test_the_command_issues_a_link(): void
    {
        $admin = $this->admin('ops@masaar.test');
        $admin->forceFill(['two_factor_secret' => self::SECRET, 'two_factor_confirmed_at' => now()])->save();

        $this->artisan('platform:enrolment-link', ['email' => 'ops@masaar.test'])->assertFailed();

        $this->artisan('platform:enrolment-link', ['email' => 'ops@masaar.test', '--reset' => true])
            ->expectsOutputToContain('/login/two-factor/enrol/')
            ->assertSuccessful();

        $this->assertFalse($admin->fresh()->hasTwoFactor());
    }

    public function test_a_localhost_link_is_flagged(): void
    {
        $this->admin('ops@masaar.test');
        config(['app.url' => 'http://localhost']);

        $this->artisan('platform:enrolment-link', ['email' => 'ops@masaar.test'])
            ->expectsOutputToContain('Set APP_URL')
            ->assertSuccessful();
    }

    private function admin(string $email): User
    {
        return User::factory()->create(['email' => $email, 'is_platform_admin' => true]);
    }

    private function links(): EnrolmentLinks
    {
        return app(EnrolmentLinks::class);
    }

    private function token(string $link): string
    {
        return basename((string) parse_url($link, PHP_URL_PATH));
    }
}
