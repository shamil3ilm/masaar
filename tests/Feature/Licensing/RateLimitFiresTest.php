<?php

declare(strict_types=1);

namespace Tests\Feature\Licensing;

use App\Domains\Licensing\Exceptions\LicenseException;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Services\UsageMeteringService;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The API rate limit refuses a call, on whichever cache store is configured.
 *
 * It did not refuse anything. checkWindowLimit opened with
 *
 *     $current = Cache::increment($cacheKey);
 *     if ($current === 1) { Cache::put($cacheKey, 1, $windowSeconds); }
 *     if ($current > $limit) { throw ...; }
 *
 * and config/cache.php defaults the store to `database`, which .env.example
 * also sets. Laravel's DatabaseStore::incrementOrDecrement returns false when
 * the key's row does not exist yet (DatabaseStore.php:282). So on the first
 * call of every window: false === 1 is false, so the row is never created;
 * false > $limit is false, so nothing throws. Every later call in the window
 * takes the same branch. The limit never fired, for any licence, ever - and
 * because nothing threw, the catch that was supposed to fall back to the
 * correct implementation one method below never ran either. That left
 * checkWindowLimitDb, which is atomic and careful and has a docblock
 * explaining exactly why, as unreachable code.
 *
 * On Redis it failed differently and still failed: INCR creates the key, but
 * the Cache::put added to give it a TTL resets the counter to 1, discarding
 * every increment between the two calls. A burst counted as one request.
 *
 * The reason no test caught it is the part worth keeping. phpunit.xml sets
 * CACHE_STORE=array, and ArrayStore::increment creates the key and returns 1
 * when it is missing (ArrayStore.php:137) - so on the store the suite runs on,
 * the code was correct. UsageRaceTest then mocked Cache::increment to throw
 * in order to reach the database fallback, which meant the only path ever
 * exercised was the one production never took.
 *
 * So the limit no longer goes near the cache: checkWindowLimitDb is now the
 * implementation rather than the fallback. These tests run on both stores to
 * say that the answer does not depend on which one is configured.
 */
class RateLimitFiresTest extends TestCase
{
    use RefreshDatabase;

    private const PER_MINUTE = 2;

    private License $license;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();

        $organization = Organization::create([
            'name' => 'Acme Trading',
            'country' => 'SA',
            'vat_number' => '300000000000003',
        ]);

        $this->license = License::createWithCredentials([
            'org_id' => $organization->id,
            'organization_name' => 'Acme Trading',
            'contact_email' => 'erp@acme.test',
            'tier' => 'starter',
            'calls_per_min' => self::PER_MINUTE,
            'calls_per_day' => 1000,
        ])['license'];
    }

    /**
     * @return list<array{0: string}>
     */
    public static function stores(): array
    {
        return [
            // The store the suite has always run on, where the old code
            // happened to work.
            'array' => ['array'],

            // The store config/cache.php and .env.example actually select,
            // where it did not.
            'database' => ['database'],
        ];
    }

    #[DataProvider('stores')]
    public function test_the_limit_refuses_the_next_call(string $store): void
    {
        config(['cache.default' => $store]);

        $metering = app(UsageMeteringService::class);

        for ($call = 1; $call <= self::PER_MINUTE; $call++) {
            $metering->checkRateLimit($this->license);
        }

        $this->expectException(LicenseException::class);

        $metering->checkRateLimit($this->license);
    }

    /**
     * And it counts every call rather than resetting.
     *
     * The Redis half of the defect: the put that followed the increment put
     * the counter back to 1, so a burst was counted once.
     */
    #[DataProvider('stores')]
    public function test_the_limit_counts_each_call(string $store): void
    {
        config(['cache.default' => $store]);

        app(UsageMeteringService::class)->checkRateLimit($this->license);
        app(UsageMeteringService::class)->checkRateLimit($this->license);

        $this->assertSame(
            self::PER_MINUTE,
            (int) DB::table('license_rate_limits')
                ->where('license_id', $this->license->id)
                ->where('window_type', 'minute')
                ->value('request_count'),
            'the window did not record both calls'
        );
    }

    /**
     * The daily window is also what the usage report counts.
     *
     * UsageReportingService::getApiCallCount() read a cache key that only a
     * never-called method wrote, so every report sent to the licence server
     * carried api_calls: 0. It now sums these rows, which only exist because
     * the limiter reaches the database at all.
     */
    #[DataProvider('stores')]
    public function test_the_daily_window_is_recorded(string $store): void
    {
        config(['cache.default' => $store]);

        app(UsageMeteringService::class)->checkRateLimit($this->license);

        $this->assertSame(
            1,
            (int) DB::table('license_rate_limits')
                ->where('window_type', 'day')
                ->where('window_key', now()->format('Y-m-d'))
                ->sum('request_count'),
            'the usage report would have nothing to count'
        );
    }
}
