<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A suspended account is shut out everywhere, at once.
 *
 * The portal's login already refused one - status is part of its credential
 * set - and that was the only place anything looked. The API's login did not
 * check, so a suspended user could still be issued a token. A token or a
 * session issued before the suspension went on working until it expired,
 * which for a remembered session is years. The moments anyone suspends an
 * account are a dismissal and a breach, which are exactly the moments
 * "eventually" is not good enough.
 */
class SuspendedAccountTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = Organization::create(['name' => 'Acme Trading', 'country' => 'SA']);

        $this->user = User::factory()->create(['email' => 'clerk@acme.test']);
        $this->user->organizations()->attach($organization->id, ['role' => 'member', 'status' => 'active']);
    }

    public function test_a_suspended_account_gets_no_token(): void
    {
        $this->user->update(['status' => 'suspended']);

        $this->postJson('/api/auth/login', ['email' => 'clerk@acme.test', 'password' => 'password'])
            ->assertUnauthorized();
    }

    public function test_an_issued_token_stops_working(): void
    {
        $token = $this->postJson('/api/auth/login', ['email' => 'clerk@acme.test', 'password' => 'password'])
            ->json('data.token.access_token');

        $this->withToken($token)->getJson('/api/auth/me')->assertOk();

        $this->user->update(['status' => 'suspended']);

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.message', 'Account suspended');
    }

    public function test_an_open_session_is_signed_out(): void
    {
        $this->actingAs($this->user);
        $this->user->update(['status' => 'suspended']);

        $this->get('/portal')->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
