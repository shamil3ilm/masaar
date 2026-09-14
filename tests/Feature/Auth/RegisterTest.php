<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domains\Audit\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * Registering an account over the API.
 *
 * The account and the audit entry that records its creation are one fact:
 * an account that exists with no record of how it came to is exactly the gap
 * an incident reconstruction cannot close.
 */
class RegisterTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Str0ng!Passw0rd#';

    protected function setUp(): void
    {
        parent::setUp();

        // The breached-password rule asks an external service; answer "not
        // breached" without the network.
        Http::fake();
    }

    public function test_register_creates_active_account(): void
    {
        $response = $this->register()
            ->assertCreated()
            ->assertJsonPath('message', 'User registered successfully')
            ->assertJsonPath('data.user.name', 'New Person')
            ->assertJsonPath('data.user.email', 'new@masaar.test');

        $this->assertSame(['id', 'name', 'email'], array_keys($response->json('data.user')));
        $this->assertIsString($response->json('data.token.access_token'));

        $this->assertDatabaseHas('users', ['email' => 'new@masaar.test', 'status' => 'active']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.register',
            'user_id' => $response->json('data.user.id'),
        ]);
    }

    public function test_failed_audit_leaves_no_account(): void
    {
        $this->partialMock(AuditService::class, fn ($mock) => $mock
            ->shouldReceive('logAuth')
            ->andThrow(new RuntimeException('audit unavailable')));

        $this->register()->assertServerError();

        $this->assertDatabaseMissing('users', ['email' => 'new@masaar.test']);
    }

    private function register(): TestResponse
    {
        return $this->postJson('/api/auth/register', [
            'name' => 'New Person',
            'email' => 'new@masaar.test',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ]);
    }
}
