<?php

declare(strict_types=1);

namespace Tests\Feature\Licensing;

use App\Domains\Licensing\Exceptions\LicenseException;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Services\LicenseValidationService;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suspending a licence has to stop it working now, not in five minutes.
 *
 * Validation caches the licence for the whole TTL and reads the status off the
 * cached copy, so suspending or revoking one left it authenticating until the
 * entry expired. For a commercial suspension that is merely untidy. For a
 * revocation after a leaked key it is five minutes of continued access with a
 * credential somebody else holds.
 *
 * The one invalidation that existed forgot "license:{api_key}" while the cache
 * stores under "license:" and the SHA-256 of the key — a different string, so
 * it cleared nothing and no test noticed, because the paths either side both
 * looked right on their own.
 *
 * Invalidation is now a model concern rather than something each mutating
 * service has to remember: any save or delete drops the entry, so a mutation
 * added later cannot forget.
 */
class LicenseCacheTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'the-api-secret';

    private License $license;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = Organization::create(['name' => 'Acme', 'country' => 'SA']);

        $this->license = License::create([
            'org_id' => $organization->id,
            'api_key' => 'cp_test_acme',
            'api_secret_hash' => License::hashSecret(self::SECRET),
            'organization_name' => 'Acme',
            'contact_email' => 'ops@masaar.test',
            'environment' => 'sandbox',
            'tier' => 'starter',
            'status' => 'active',
            'scopes' => ['invoice.submit'],
        ]);
    }

    public function test_an_active_licence_authenticates(): void
    {
        $this->assertSame($this->license->id, $this->authenticate()->id);
    }

    public function test_suspension_takes_effect_at_once(): void
    {
        // Warm the cache, as any earlier request would have.
        $this->authenticate();

        $this->license->suspend('non-payment');

        $this->expectException(LicenseException::class);

        $this->authenticate();
    }

    public function test_revocation_takes_effect_at_once(): void
    {
        $this->authenticate();

        $this->license->delete();

        $this->expectException(LicenseException::class);

        $this->authenticate();
    }

    /**
     * A rotated secret must stop the old one working immediately — that is the
     * point of rotating it.
     */
    public function test_rotated_secret_retires_old(): void
    {
        $this->authenticate();

        $this->license->update(['api_secret_hash' => License::hashSecret('a-new-secret')]);

        $this->expectException(LicenseException::class);

        $this->authenticate();
    }

    private function authenticate(): License
    {
        return app(LicenseValidationService::class)
            ->validateAndGetLicense('cp_test_acme', self::SECRET);
    }
}
