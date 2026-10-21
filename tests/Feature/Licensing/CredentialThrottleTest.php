<?php

declare(strict_types=1);

namespace Tests\Feature\Licensing;

use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Services\CredentialThrottle;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Wrong partner credentials are counted, and every wrong one is answered
 * alike.
 *
 * Unthrottled, a caller could guess secrets or probe for keys without limit,
 * and the two errors told it which keys exist: "Invalid API key" for one that
 * does not, "Invalid API secret" for one that does.
 */
class CredentialThrottleTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret-acme';

    protected function setUp(): void
    {
        parent::setUp();

        $organization = Organization::create(['name' => 'Acme', 'country' => 'SA']);

        License::create([
            'org_id' => $organization->id,
            'api_key' => 'cp_test_acme',
            'api_secret_hash' => License::hashSecret(self::SECRET),
            'organization_name' => 'Acme',
            'contact_email' => 'ops@example.test',
            'environment' => 'sandbox',
            'tier' => 'starter',
            'status' => 'active',
            'scopes' => ['invoice.read'],
        ]);
    }

    public function test_unknown_key_and_wrong_secret_match(): void
    {
        $unknown = $this->attempt('cp_test_nobody', self::SECRET)->assertUnauthorized();
        $wrong = $this->attempt('cp_test_acme', 'not-the-secret')->assertUnauthorized();

        $this->assertSame($unknown->json('error'), $wrong->json('error'));
    }

    public function test_repeated_wrong_secrets_are_throttled(): void
    {
        for ($i = 0; $i < CredentialThrottle::PER_KEY_AND_ADDRESS; $i++) {
            $this->attempt('cp_test_acme', 'guess-'.$i)->assertUnauthorized();
        }

        // Locked for that key from that address, even with the right secret.
        $this->attempt('cp_test_acme', self::SECRET)
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'LICENSE_RATE_LIMITED')
            ->assertHeader('Retry-After');

        // Not for the partner calling from its own address.
        $this->attempt('cp_test_acme', self::SECRET, '198.51.100.7')->assertOk();
    }

    public function test_probing_many_keys_is_throttled(): void
    {
        for ($i = 0; $i < CredentialThrottle::PER_ADDRESS; $i++) {
            $this->attempt('cp_test_probe'.$i, 'x')->assertUnauthorized();
        }

        $this->attempt('cp_test_probe-next', 'x')->assertStatus(429);
    }

    public function test_success_forgives_earlier_failures(): void
    {
        for ($i = 0; $i < CredentialThrottle::PER_KEY_AND_ADDRESS - 1; $i++) {
            $this->attempt('cp_test_acme', 'guess-'.$i)->assertUnauthorized();
        }

        $this->attempt('cp_test_acme', self::SECRET)->assertOk();
        $this->attempt('cp_test_acme', 'one-more-typo')->assertUnauthorized();
        $this->attempt('cp_test_acme', self::SECRET)->assertOk();
    }

    private function attempt(string $key, string $secret, string $address = '203.0.113.9'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $address])
            ->withHeaders(['X-API-Key' => $key, 'X-API-Secret' => $secret])
            ->getJson('/api/v1/invoices');
    }
}
