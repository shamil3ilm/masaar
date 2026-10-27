<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every 429 carries Retry-After, not only a number in the body.
 *
 * The per-band limit answered with details.retry_after and no header, and the
 * framework's own throttle lost the header it set on the way through the API
 * error envelope. A client that honours the header retried at once.
 */
class RetryAfterHeaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_band_limit_sends_retry_after(): void
    {
        config(['security.rate_limits.anonymous' => 1]);

        $this->postJson('/api/auth/login', ['email' => 'a@example.test', 'password' => 'x']);

        $response = $this->postJson('/api/auth/login', ['email' => 'a@example.test', 'password' => 'x'])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertHeader('Retry-After');

        $this->assertSame((string) $response->json('error.retry_after'), $response->headers->get('Retry-After'));
        $this->assertSame($response->json('error.retry_after'), $response->json('error.details.retry_after'));
    }

    public function test_route_throttle_keeps_retry_after(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/email/resend', ['email' => 'a@example.test']);
        }

        $this->postJson('/api/auth/email/resend', ['email' => 'a@example.test'])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertHeader('Retry-After');
    }
}
