<?php

declare(strict_types=1);

namespace Tests\Feature\Licensing;

use App\Domains\Licensing\Services\PlatformLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The gate that decides whether this deployment may serve at all.
 *
 * PlatformLicense was written, aliased in AppServiceProvider, and attached to
 * no route — so the product's own commercial gate never ran on a single
 * request. It is on the api group now, because "may this deployment serve"
 * is not a question about who is calling.
 *
 * .env.example shipped PLATFORM_LICENSE_ENABLED=true with an empty key, which
 * means enforce with nothing to enforce against: every request refused. That
 * was harmless only while nothing enforced it, and is why the default is now
 * false with production setting it true alongside a key.
 */
class PlatformLicenseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        // No phone-home. The remote call is a five-second timeout with two
        // retries against a host that does not exist, and offline validation is
        // what these tests are about.
        config(['platform-license.server_url' => null]);

        // Stated, because the service no longer supplies one: these tests
        // signed their keys with a default written into the service, and
        // passed only because every deployment shared it.
        config(['platform-license.signing_secret' => 'test-licence-secret']);
    }

    /**
     * The state a fresh checkout is in: no key, enforcement off, everything
     * serves. Turning enforcement on is a deliberate act.
     */
    public function test_disabled_gate_lets_requests_through(): void
    {
        config(['platform-license.enabled' => false, 'platform-license.key' => '']);

        $this->getJson('/api/v1/invoices')->assertStatus(401);
    }

    /**
     * Enabled with no key refuses, and says why. 403 rather than 401: the
     * caller's credential is not the problem.
     */
    public function test_enabled_without_a_key_refuses(): void
    {
        config(['platform-license.enabled' => true, 'platform-license.key' => '']);

        $this->getJson('/api/v1/invoices')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'LICENSE_INVALID');
    }

    /**
     * A deployment without a licence must still be able to answer whether it is
     * healthy, or an expired key takes the monitoring down with it.
     */
    public function test_health_is_reachable_without_a_licence(): void
    {
        config([
            'platform-license.enabled' => true,
            'platform-license.key' => '',
            'platform-license.excluded_paths' => ['api/health', 'api/license/status'],
        ]);

        $this->getJson('/api/health')->assertStatus(200);
    }

    /**
     * And so must the readiness probe, under the shipped configuration.
     *
     * The exclusions matched api/health exactly, so api/health/ready went
     * through the licence check: without a key it answered 403 and never
     * ready. The load balancer routes on that probe, so a lapsed or
     * unreachable licence took every replica out of rotation at once, and
     * the 403 said nothing about why. Licensing answers whether a request may
     * be served; readiness answers whether the platform can serve one.
     *
     * Read from config/platform-license.php rather than set here, which is
     * what the test above does and why it could not have caught this.
     */
    /**
     * No secret, no licence - and no crash.
     *
     * The service fell back to a secret written in its own source, so any
     * deployment that forgot PLATFORM_LICENSE_SECRET accepted licence keys
     * anyone could sign. And when the config key held null rather than being
     * absent, the fallback never applied and the typed property refused null:
     * constructing the middleware threw, and every API request was a 500.
     */
    public function test_no_secret_refuses_cleanly(): void
    {
        config([
            'platform-license.enabled' => true,
            'platform-license.key' => 'ACME-PROD-20991231-abcdef12',
            'platform-license.signing_secret' => null,
        ]);

        $this->getJson('/api/v1/invoices')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'LICENSE_INVALID');
        $this->getJson('/api/health/ready')->assertStatus(200);
    }

    /**
     * And a key signed with the old built-in secret proves nothing.
     */
    public function test_the_old_default_secret_signs_nothing(): void
    {
        $forged = 'ACME-PROD-20991231-'.substr(hash_hmac('sha256', 'ACME-PROD-20991231', 'platform-default-secret-change-me'), 0, 8);

        config([
            'platform-license.enabled' => true,
            'platform-license.key' => $forged,
            'platform-license.signing_secret' => null,
            'platform-license.server_url' => null,
        ]);

        $this->assertFalse(app(PlatformLicenseService::class)->validate()['valid']);
    }

    public function test_readiness_ignores_the_licence(): void
    {
        config([
            'platform-license.enabled' => true,
            'platform-license.key' => '',
        ]);

        $this->getJson('/api/health/ready')->assertStatus(200);
        $this->getJson('/api/health')->assertStatus(200);
    }

    /**
     * A valid key admits the request, so the gate is not simply refusing
     * everything.
     */
    public function test_a_valid_key_admits(): void
    {
        $key = app(PlatformLicenseService::class)->generateKey(
            'ACME',
            PlatformLicenseService::TYPE_PRODUCTION,
            new \DateTime('+1 year'),
        );

        config(['platform-license.enabled' => true, 'platform-license.key' => $key]);

        // The type is PROD, not 'production': generateKey() uppercases what
        // it is given and validateOffline() compares against TYPE_PRODUCTION,
        // so a spelled-out type produces a key the platform refuses.
        //
        // 401 from the licence guard beyond it, not 403 from this gate.
        $this->getJson('/api/v1/invoices')->assertStatus(401);
    }

    /**
     * An expired key is refused. The signature still verifies, so only the
     * expiry distinguishes it — which is the part that has to be checked.
     */
    public function test_an_expired_key_is_refused(): void
    {
        $key = app(PlatformLicenseService::class)->generateKey(
            'ACME',
            PlatformLicenseService::TYPE_PRODUCTION,
            new \DateTime('-1 day'),
        );

        config(['platform-license.enabled' => true, 'platform-license.key' => $key]);

        $this->getJson('/api/v1/invoices')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'LICENSE_INVALID');
    }

    /**
     * The signature is the whole HMAC-SHA256, 256 bits. It was its first 32,
     * which an offline guesser walks through in an afternoon.
     */
    public function test_issued_signature_is_full_length(): void
    {
        $key = $this->key('+1 year');

        $this->assertMatchesRegularExpression('/^ACME-PROD-\d{8}-[0-9a-f]{64}$/', $key);
        $this->assertTrue($this->validates($key));
    }

    /**
     * A key in the old 32-bit format is refused, even when it is the true
     * prefix of the right signature: no offline key was ever issued in it.
     */
    public function test_truncated_signature_is_refused(): void
    {
        $key = $this->key('+1 year');

        $this->assertFalse($this->validates(substr($key, 0, -56)));
    }

    public function test_tampered_signature_is_refused(): void
    {
        $key = $this->key('+1 year');
        $last = substr($key, -1);

        $this->assertFalse($this->validates(substr($key, 0, -1).($last === 'a' ? 'b' : 'a')));
    }

    /**
     * The signature covers what the key claims: a later expiry or a better
     * type under the same signature is a forgery.
     */
    public function test_altered_claims_are_refused(): void
    {
        $key = $this->key('+1 year');
        [, , $expiry, $signature] = explode('-', $key);

        $this->assertFalse($this->validates("ACME-PROD-20991231-{$signature}"));
        $this->assertFalse($this->validates("OTHER-PROD-{$expiry}-{$signature}"));
    }

    public function test_forged_signature_is_refused(): void
    {
        $forged = 'ACME-PROD-20991231-'.hash_hmac('sha256', 'ACME-PROD-20991231', 'not-the-secret');

        $this->assertFalse($this->validates($forged));
        $this->assertFalse($this->validates('ACME-PROD-20991231-'.str_repeat('0', 64)));
        $this->assertFalse($this->validates('ACME-PROD-20991231-'.strtoupper(substr($this->key('+1 year'), -64))));
    }

    private function key(string $expires): string
    {
        return app(PlatformLicenseService::class)->generateKey('ACME', PlatformLicenseService::TYPE_PRODUCTION, new \DateTime($expires));
    }

    private function validates(string $key): bool
    {
        Cache::flush();
        config(['platform-license.key' => $key]);

        return app(PlatformLicenseService::class)->validate()['valid'];
    }
}
