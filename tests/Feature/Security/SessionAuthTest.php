<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Covers the session credential exchange that gates the Blade consoles.
 */
class SessionAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_credentials_authenticate(): void
    {
        $user = User::factory()->create(['email' => 'operator@masaar.test']);

        $this->post('/login', [
            'email' => 'operator@masaar.test',
            'password' => 'password',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }

    public function test_invalid_password_is_rejected(): void
    {
        User::factory()->create(['email' => 'operator@masaar.test']);

        $this->post('/login', [
            'email' => 'operator@masaar.test',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /**
     * `status` is part of the credential set, so deactivating an account
     * revokes login without needing to rotate its password.
     */
    public function test_suspended_account_cannot_authenticate(): void
    {
        User::factory()->suspended()->create(['email' => 'suspended@masaar.test']);

        $this->post('/login', [
            'email' => 'suspended@masaar.test',
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_repeated_failures_are_throttled(): void
    {
        User::factory()->create(['email' => 'operator@masaar.test']);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', [
                'email' => 'operator@masaar.test',
                'password' => 'wrong-password',
            ]);
        }

        // The correct password must not succeed while the lockout holds.
        $this->post('/login', [
            'email' => 'operator@masaar.test',
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_session_identifier_is_rotated_on_login(): void
    {
        User::factory()->create(['email' => 'operator@masaar.test']);

        $this->get('/login');
        $before = session()->getId();

        $this->post('/login', [
            'email' => 'operator@masaar.test',
            'password' => 'password',
        ]);

        $this->assertNotSame($before, session()->getId());
    }

    public function test_platform_admin_lands_on_the_admin_console(): void
    {
        User::factory()->platformAdmin()->create(['email' => 'admin@masaar.test']);

        $this->post('/login', [
            'email' => 'admin@masaar.test',
            'password' => 'password',
        ])->assertRedirect(route('admin.dashboard'));
    }

    public function test_regular_user_lands_on_the_portal(): void
    {
        User::factory()->create(['email' => 'user@masaar.test']);

        $this->post('/login', [
            'email' => 'user@masaar.test',
            'password' => 'password',
        ])->assertRedirect(route('portal.dashboard'));
    }

    public function test_logout_clears_the_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
        $this->assertNull(Auth::user());
    }

    public function test_logout_requires_authentication(): void
    {
        $this->post('/logout')->assertRedirect('/login');
    }
}
