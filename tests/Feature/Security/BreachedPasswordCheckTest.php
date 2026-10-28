<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domains\Auth\Services\BreachedPasswordCheck;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The breached-password check asks an outside range service. A slow or
 * unreachable service no longer holds a registration up for thirty seconds:
 * it is given a few, and then the password is judged on the other rules,
 * with a warning that names neither the password nor its hash. A password the
 * service does list is still refused.
 */
class BreachedPasswordCheckTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Password123!';

    public function test_unreachable_service_lets_registration_through(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection timed out after 3001 milliseconds'));
        Log::spy();

        $this->register()->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'new@example.test']);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'breached-password check')
            && ! str_contains(json_encode($context).$message, self::PASSWORD)
            && ! str_contains(json_encode($context).$message, strtoupper(sha1(self::PASSWORD))))->once();
    }

    public function test_failing_service_lets_registration_through(): void
    {
        Http::fake(['*' => Http::response('', 503)]);
        Log::spy();

        $this->register()->assertCreated();

        Log::shouldHaveReceived('warning')->once();
    }

    public function test_a_listed_password_is_refused(): void
    {
        $hash = strtoupper(sha1(self::PASSWORD));
        Http::fake(['*' => Http::response(substr($hash, 5).":42\r\n0000000000000000000000000000000000A:1", 200)]);

        $this->register()->assertStatus(422)->assertJsonValidationErrorFor('password', 'error.details');
        $this->assertDatabaseMissing('users', ['email' => 'new@example.test']);
    }

    public function test_the_service_gets_a_short_timeout(): void
    {
        config(['security.password_breach_check.timeout_seconds' => 3]);
        Http::fake(['*' => Http::response('', 200)]);

        $this->assertTrue(app(UncompromisedVerifier::class)->verify(['value' => self::PASSWORD, 'threshold' => 0]));

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/'.substr(strtoupper(sha1(self::PASSWORD)), 0, 5)));
        $this->assertSame(3, app(UncompromisedVerifier::class)->timeout());
    }

    /**
     * Registration, sign-up, invitations, the password change and the reset
     * all ask through Password::uncompromised(), which resolves this binding.
     */
    public function test_every_password_rule_uses_it(): void
    {
        $this->assertInstanceOf(BreachedPasswordCheck::class, app(UncompromisedVerifier::class));
    }

    private function register(): TestResponse
    {
        return $this->postJson('/api/auth/register', [
            'name' => 'New Person',
            'email' => 'new@example.test',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ]);
    }
}
