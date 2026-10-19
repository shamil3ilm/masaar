<?php

declare(strict_types=1);

namespace Tests\Feature\Licensing;

use App\Domains\Licensing\Exceptions\LicenseException;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Services\LicenseValidationService;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
            'contact_email' => 'ops@example.test',
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

    /**
     * The entry is dropped after the commit, not during the transaction.
     *
     * The tests above pass either way, because nothing reads the licence
     * between the UPDATE and the COMMIT. That gap is the whole defect.
     * suspend() wraps its UPDATE and its audit INSERT in one transaction, and
     * the forget fired from the saved() hook ran inside it - so a request
     * arriving in the gap, on another connection that cannot see the
     * uncommitted row, read the licence as Active and cached that copy for
     * the full TTL. The credential that had just been revoked kept working.
     *
     * Two connections with real isolation are needed to reproduce that, which
     * this suite cannot do: it runs one connection against an in-memory
     * SQLite database, and a second connection would be a different database
     * entirely. So what is asserted here is the ordering the fix changes, and
     * which is observable on one connection - at the moment the UPDATE runs
     * the entry is still there, and once suspend() returns it is gone. Before
     * the fix the first of those was false.
     */
    public function test_the_entry_outlives_the_transaction(): void
    {
        $this->authenticate();

        $key = $this->license->cacheKey();
        $presentDuringUpdate = null;

        $this->duringTheAuditWrite(function () use ($key, &$presentDuringUpdate): void {
            $presentDuringUpdate = Cache::has($key);
        });

        $this->license->suspend('non-payment');

        $this->assertTrue(
            $presentDuringUpdate,
            'the entry was dropped while the transaction was still open, so a read in that gap could refill it from the pre-commit row'
        );

        $this->assertFalse(Cache::has($key), 'the entry survived the commit');
    }

    private function duringTheAuditWrite(\Closure $competitor): void
    {
        $fired = false;

        DB::listen(function (QueryExecuted $query) use (&$fired, $competitor): void {
            // The audit INSERT, which is the last statement inside the
            // transaction and runs after update() has fired the saved() hook.
            // Probing the UPDATE instead would see the cache before the hook
            // had run at all, which is true in either version and proves
            // nothing. Matched without a quoting style so it holds on SQLite
            // and MySQL alike.
            if ($fired || ! preg_match('/^insert\s+into\s+.?license_audit_logs.?/i', trim($query->sql))) {
                return;
            }

            $fired = true;
            $competitor();
        });
    }

    private function authenticate(): License
    {
        return app(LicenseValidationService::class)
            ->validateAndGetLicense('cp_test_acme', self::SECRET);
    }
}
