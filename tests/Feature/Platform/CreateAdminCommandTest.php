<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A fresh deployment can get into its own console.
 *
 * `composer setup` migrates and stops. Nothing seeds, and DatabaseSeeder
 * would not have helped: it creates one "Test User" with no
 * is_platform_admin flag and a factory password. So the console came up with
 * no account able to reach it, and the only remedy was an UPDATE by hand
 * against production.
 *
 * Granting platform admin is granting a view of every taxpayer on the
 * platform, so the parts of this worth testing are the refusals as much as
 * the creation.
 */
class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    private const GOOD = 'Str0ng-Enough!Pass';

    public function test_it_creates_an_administrator(): void
    {
        $this->artisan('masaar:create-admin', ['email' => 'ops@masaar.test'])
            ->expectsQuestion('Password', self::GOOD)
            ->expectsQuestion('Confirm password', self::GOOD)
            ->assertSuccessful();

        $user = User::where('email', 'ops@masaar.test')->firstOrFail();

        $this->assertTrue($user->is_platform_admin);
        $this->assertTrue(Hash::check(self::GOOD, $user->password));
    }

    /**
     * The password is asked for, not passed, so it is not in the shell
     * history or the process list. A mistyped confirmation creates nothing.
     */
    public function test_a_mismatch_creates_nothing(): void
    {
        $this->artisan('masaar:create-admin', ['email' => 'ops@masaar.test'])
            ->expectsQuestion('Password', self::GOOD)
            ->expectsQuestion('Confirm password', 'something else entirely')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'ops@masaar.test']);
    }

    public function test_a_weak_password_is_refused(): void
    {
        // Refused on the first answer, so the confirmation is never asked -
        // expecting it here would fail on the unasked question rather than
        // on the behaviour.
        $this->artisan('masaar:create-admin', ['email' => 'ops@masaar.test'])
            ->expectsQuestion('Password', 'password')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'ops@masaar.test']);
    }

    /**
     * The one that matters most. Running this against an address that already
     * belongs to an ordinary user must not quietly hand them every taxpayer's
     * data - it has to be asked for.
     */
    public function test_an_existing_user_is_not_promoted(): void
    {
        $user = User::factory()->create([
            'email' => 'member@masaar.test',
            'is_platform_admin' => false,
        ]);

        $this->artisan('masaar:create-admin', ['email' => 'member@masaar.test'])
            ->assertFailed();

        $this->assertFalse($user->fresh()->is_platform_admin);
    }

    public function test_promote_grants_it_deliberately(): void
    {
        $user = User::factory()->create([
            'email' => 'member@masaar.test',
            'is_platform_admin' => false,
        ]);

        $this->artisan('masaar:create-admin', ['email' => 'member@masaar.test', '--promote' => true])
            ->assertSuccessful();

        $this->assertTrue($user->fresh()->is_platform_admin);
    }

    /**
     * A grant to an account that cannot sign in is not a grant.
     *
     * isPlatformAdmin() requires status === 'active', and so does logging in.
     * Promoting a suspended account reported success and changed nothing an
     * operator could see - then handed that account every taxpayer's data the
     * day somebody reactivated it.
     */
    public function test_a_suspended_user_is_refused(): void
    {
        $user = User::factory()->create([
            'email' => 'ex@masaar.test',
            'status' => 'suspended',
            'is_platform_admin' => false,
        ]);

        $this->artisan('masaar:create-admin', ['email' => 'ex@masaar.test', '--promote' => true])
            ->assertFailed();

        $this->assertFalse($user->fresh()->is_platform_admin);
    }

    /**
     * And the grant leaves a record. Logins are audited; the action that hands
     * somebody cross-tenant access was not, so the only evidence was a shell
     * history.
     */
    public function test_a_grant_is_audited(): void
    {
        User::factory()->create([
            'email' => 'member@masaar.test',
            'status' => 'active',
            'is_platform_admin' => false,
        ]);

        $this->artisan('masaar:create-admin', ['email' => 'member@masaar.test', '--promote' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'security.platform_admin.granted',
        ]);
    }

    /**
     * The command makes the platform's one owner. Once there is one, it
     * makes nobody - not a new account, not an existing one with --promote.
     */
    public function test_an_owner_ends_the_command(): void
    {
        $this->artisan('masaar:create-admin', ['email' => 'first@masaar.test'])
            ->expectsQuestion('Password', self::GOOD)
            ->expectsQuestion('Confirm password', self::GOOD)
            ->assertSuccessful();
        $this->assertSame('owner', User::where('email', 'first@masaar.test')->value('platform_role'));

        $member = User::factory()->create(['email' => 'member@masaar.test', 'is_platform_admin' => false]);

        $this->artisan('masaar:create-admin', ['email' => 'member@masaar.test', '--promote' => true])->assertFailed();
        $this->artisan('masaar:create-admin', ['email' => 'second@masaar.test'])->assertFailed();

        $this->assertFalse($member->fresh()->is_platform_admin);
        $this->assertDatabaseMissing('users', ['email' => 'second@masaar.test']);
    }

    public function test_a_bad_address_is_refused(): void
    {
        $this->artisan('masaar:create-admin', ['email' => 'not-an-address'])
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'not-an-address']);
    }
}
