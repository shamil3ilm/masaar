<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domains\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Exercises the JWT stack end to end against the real token library.
 *
 * The unit tests in tests/Unit/Domains/Auth mock Tymon\JWTAuth wholesale, so
 * they assert the wrapper's branching and never encode or decode a token. That
 * leaves the library underneath — lcobucci/jwt, which signs and parses — with
 * no coverage at all: it could stop producing valid signatures and those tests
 * would still pass.
 *
 * Everything here issues a genuine token and sends it over HTTP, so a
 * regression in signing, claim encoding, expiry handling or blacklisting fails
 * here rather than in production.
 */
class JwtTokenTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create([
            'email' => 'token-subject@masaar.test',
        ]);
    }

    private function login(string $email = 'token-subject@masaar.test'): string
    {
        return $this->postJson('/api/auth/login', [
            'email' => $email,
            'password' => 'password',
        ])->assertOk()->json('data.token.access_token');
    }

    // -------------------------------------------------------------------
    // Issue
    // -------------------------------------------------------------------

    public function test_login_issues_token(): void
    {
        $this->user();

        $token = $this->login();

        $this->assertIsString($token);
        $this->assertCount(
            3,
            explode('.', $token),
            'A JWS is header.payload.signature — three base64url segments.'
        );
    }

    /**
     * The claim set is a contract: JwtGuard reads `sub` to resolve the user and
     * `org_id`/`role` to establish tenant context. A library upgrade that
     * renamed or re-typed claims would break authorisation silently.
     */
    public function test_token_carries_claims(): void
    {
        $user = $this->user();

        $payload = JWTAuth::setToken($this->login())->getPayload();

        $this->assertSame((string) $user->getKey(), (string) $payload->get('sub'));
        $this->assertIsInt($payload->get('exp'));
        $this->assertIsInt($payload->get('iat'));
        $this->assertGreaterThan($payload->get('iat'), $payload->get('exp'));
    }

    // -------------------------------------------------------------------
    // Consume
    // -------------------------------------------------------------------

    public function test_token_authenticates(): void
    {
        $user = $this->user();

        $this->withToken($this->login())
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', $user->email);
    }

    public function test_missing_token_denied(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    /**
     * Flipping one character of the signature must invalidate the token. If
     * this ever passes, signature verification is not running.
     */
    public function test_tampered_signature_denied(): void
    {
        $this->user();

        [$header, $payload, $signature] = explode('.', $this->login());
        $signature[0] = $signature[0] === 'A' ? 'B' : 'A';

        $this->withToken("{$header}.{$payload}.{$signature}")
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    /**
     * Editing the payload invalidates the signature, so a caller cannot
     * escalate by rewriting their own `sub`.
     */
    public function test_tampered_payload_denied(): void
    {
        $this->user();
        $other = User::factory()->create(['email' => 'victim@masaar.test']);

        [$header, , $signature] = explode('.', $this->login());

        $forged = rtrim(strtr(base64_encode(json_encode([
            'sub' => $other->getKey(),
            'iat' => time(),
            'exp' => time() + 3600,
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        $this->withToken("{$header}.{$forged}.{$signature}")
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_garbage_token_denied(): void
    {
        $this->withToken('not-a-token')
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    // -------------------------------------------------------------------
    // Expiry
    // -------------------------------------------------------------------

    /**
     * Expiry is enforced from the `exp` claim rather than from any server-side
     * record, so travelling past it must close the token off.
     */
    public function test_expired_token_denied(): void
    {
        $this->user();
        $token = $this->login();

        $this->travel(config('jwt.ttl') + 1)->minutes();

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    /**
     * The other half of the expiry pair. Without this, the test above would
     * still pass if time travel simply broke the request, or if the TTL were
     * misconfigured to something far shorter than intended — it pins the
     * rejection to the boundary rather than to travelling at all.
     */
    public function test_valid_before_expiry(): void
    {
        $this->user();
        $token = $this->login();

        $this->travel(config('jwt.ttl') - 1)->minutes();

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertOk();
    }

    // -------------------------------------------------------------------
    // Refresh & revoke
    // -------------------------------------------------------------------

    public function test_refresh_issues_token(): void
    {
        $user = $this->user();

        $refreshed = $this->withToken($this->login())
            ->postJson('/api/auth/refresh')
            ->assertOk()
            ->json('data.token.access_token');

        $this->assertIsString($refreshed);

        $this->withToken($refreshed)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', $user->email);
    }

    /**
     * Logout blacklists the token. Without this, a leaked token stays valid
     * for its full TTL and "log out" is cosmetic.
     */
    public function test_logout_revokes_token(): void
    {
        $this->user();
        $token = $this->login();

        $this->withToken($token)
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }
}
