<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domains\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two endpoints that take credentials have to refuse a flood.
 *
 * Laravel 11 removed `throttle:api` from the default api middleware group, and
 * this application's group prepends CORS and the platform licence and nothing
 * else - so `throttle` applied only where a route asked for it, and of the
 * public routes only /metrics did. Login and register, the two endpoints where
 * an attacker gets something for guessing, had no limit at all: an unlimited
 * number of password attempts per second against a known address, and
 * unlimited account creation.
 *
 * The limits are deliberately low. A client of this API authenticates once and
 * reuses the token until it expires, so no legitimate caller needs more than a
 * few attempts a minute, and nothing about invoice throughput passes through
 * here - submission limits are SubmissionGuard's, per organization, and are
 * not affected by these.
 */
class AuthThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_logins_are_throttled(): void
    {
        User::factory()->create(['email' => 'target@masaar.test']);

        $statuses = [];

        for ($attempt = 0; $attempt < 25; $attempt++) {
            $statuses[] = $this->postJson('/api/auth/login', [
                'email' => 'target@masaar.test',
                'password' => 'not-the-password',
            ])->getStatusCode();
        }

        $this->assertContains(
            429,
            $statuses,
            'Twenty-five password attempts in a row were all answered. '
                .'The login endpoint has no rate limit.'
        );
    }

    public function test_repeated_registrations_are_throttled(): void
    {
        $statuses = [];

        for ($attempt = 0; $attempt < 25; $attempt++) {
            $statuses[] = $this->postJson('/api/auth/register', [
                'name' => 'Flood '.$attempt,
                'email' => "flood{$attempt}@masaar.test",
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ])->getStatusCode();
        }

        $this->assertContains(
            429,
            $statuses,
            'Twenty-five accounts were created in a row without a refusal.'
        );
    }

    /**
     * A limit that refused a correct password would lock users out, so the
     * first attempt has to be answered on its merits.
     */
    public function test_a_first_login_still_works(): void
    {
        User::factory()->create(['email' => 'legitimate@masaar.test']);

        $this->postJson('/api/auth/login', [
            'email' => 'legitimate@masaar.test',
            'password' => 'password',
        ])->assertSuccessful();
    }
}
