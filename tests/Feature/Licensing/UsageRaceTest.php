<?php

declare(strict_types=1);

namespace Tests\Feature\Licensing;

use App\Domains\Licensing\Exceptions\LicenseException;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Services\UsageMeteringService;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Metering counters under concurrent requests.
 *
 * Each race is reproduced by running the competing request immediately after
 * the first query of the one under test — the gap a read-then-write leaves.
 */
class UsageRaceTest extends TestCase
{
    use RefreshDatabase;

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
            'calls_per_min' => 1,
            'calls_per_day' => 1000,
        ])['license'];
    }

    /**
     * The database fallback admits no more than the limit when another
     * request takes the last slot between this one's read and its write.
     */
    public function test_rate_limit_holds_under_contention(): void
    {
        $window = now()->format('Y-m-d-H-i');

        DB::table('license_rate_limits')->insert([
            'id' => Str::uuid()->toString(),
            'license_id' => $this->license->id,
            'window_type' => 'minute',
            'window_key' => $window,
            'request_count' => 0,
            'window_start' => now(),
            'window_expires' => now()->addMinute(),
        ]);

        $this->afterFirstQueryOn('license_rate_limits', fn () => DB::table('license_rate_limits')
            ->where('license_id', $this->license->id)
            ->where('window_type', 'minute')
            ->increment('request_count'));

        Cache::shouldReceive('increment')->andThrow(new \RuntimeException('cache down'));

        try {
            app(UsageMeteringService::class)->checkRateLimit($this->license);
            $this->fail('A request past the limit was admitted.');
        } catch (LicenseException) {
            $count = DB::table('license_rate_limits')->where('window_type', 'minute')->value('request_count');

            $this->assertSame(1, (int) $count);
        }
    }

    private function afterFirstQueryOn(string $table, \Closure $competitor): void
    {
        $fired = false;

        DB::listen(function (QueryExecuted $query) use (&$fired, $table, $competitor): void {
            if ($fired || ! str_contains($query->sql, "\"{$table}\"")) {
                return;
            }

            $fired = true;
            $competitor();
        });
    }
}
