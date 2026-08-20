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
            ->assertJsonPath('error', 'license_invalid');
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
            ->assertJsonPath('error', 'license_invalid');
    }
}
