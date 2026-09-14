<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domains\Compliance\Fatoora\Services\CircuitBreaker;
use App\Domains\Compliance\Fatoora\Services\Connectivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use ReflectionClassConstant;
use Tests\TestCase;

/**
 * What the platform admin API reports, endpoint by endpoint.
 *
 * AdminApiAccessTest covers who may call these and PlatformStatusTest the
 * overview and health. This pins the rest — keys, ordering, filters and the
 * figures — across more than one tenant, since every one of them is meant to
 * see the whole platform.
 */
class AdminDashboardTest extends TestCase
{
    use PlatformRows;
    use RefreshDatabase;

    public function test_top_organizations_ranked_by_invoices(): void
    {
        $acme = $this->organization('Acme');
        $globex = $this->organization('Globex');

        $this->invoice($acme);
        $this->invoice($acme);
        $this->invoice($acme);
        $this->invoice($globex);

        $response = $this->api()->getJson('/api/admin/dashboard/top-organizations?limit=1')->assertOk();

        $this->assertSame(1, $response->json('data.count'));
        $this->assertSame(['id', 'name', 'invoice_count', 'total_amount'], array_keys($response->json('data.organizations.0')));
        $this->assertSame($acme->id, $response->json('data.organizations.0.id'));
        $this->assertSame('Acme', $response->json('data.organizations.0.name'));
        $this->assertEquals(3, $response->json('data.organizations.0.invoice_count'));
        $this->assertEquals(345, $response->json('data.organizations.0.total_amount'));

        $this->api()->getJson('/api/admin/dashboard/top-organizations')
            ->assertOk()
            ->assertJsonPath('data.count', 2)
            ->assertJsonPath('data.organizations.1.id', $globex->id);
    }

    public function test_hash_chain_health_reports_metrics(): void
    {
        $acme = $this->organization('Acme');
        $globex = $this->organization('Globex');

        $this->chainEntry($acme, 1, ['created_at' => '2026-01-02 08:00:00']);
        $this->chainEntry($globex, 1, ['created_at' => '2026-01-01 08:00:00']);

        $response = $this->api()->getJson('/api/admin/dashboard/hash-chain-health')
            ->assertOk()
            ->assertJsonPath('data.status', 'healthy')
            ->assertJsonPath('data.recommendation', null)
            ->assertJsonPath('data.metrics.samples', 5)
            ->assertJsonPath('data.metrics.row_count', 2)
            ->assertJsonPath('data.metrics.oldest_entry', '2026-01-01 08:00:00')
            ->assertJsonStructure(['data' => [
                'metrics' => ['samples', 'avg_ms', 'p95_ms', 'p99_ms', 'thresholds' => ['p95_warning', 'p99_critical'], 'row_count', 'oldest_entry'],
                'status',
                'recommendation',
                'checked_at',
            ]]);

        $this->assertSame(['samples', 'avg_ms', 'p95_ms', 'p99_ms', 'thresholds', 'row_count', 'oldest_entry'], array_keys($response->json('data.metrics')));
    }

    /**
     * The verdict compares the sampled latencies with the configured limits:
     * past p99 is critical, past p95 a warning.
     */
    public function test_hash_chain_status_follows_thresholds(): void
    {
        config(['fatoora.hash_chain_monitoring' => ['p95_warning_ms' => -1, 'p99_critical_ms' => 1000]]);

        $this->api()->getJson('/api/admin/dashboard/hash-chain-health')
            ->assertOk()
            ->assertJsonPath('data.status', 'warning')
            ->assertJsonPath('data.metrics.thresholds', ['p95_warning' => -1, 'p99_critical' => 1000])
            ->assertJsonPath('data.recommendation', 'Consider partitioning hash_chain_history table or adding indexes');

        Cache::forget('admin:hash_chain_health');
        config(['fatoora.hash_chain_monitoring' => ['p95_warning_ms' => 1000, 'p99_critical_ms' => -1]]);

        $this->api()->getJson('/api/admin/dashboard/hash-chain-health')
            ->assertOk()
            ->assertJsonPath('data.status', 'critical');
    }

    /**
     * DATE_FORMAT is MySQL's; SQLite is given an equivalent so the grouping
     * and the rates can be checked at all.
     */
    public function test_error_rates_grouped_by_hour(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::connection()->getPdo()->sqliteCreateFunction(
                'DATE_FORMAT',
                fn (string $value, string $format) => date(strtr($format, ['%Y' => 'Y', '%m' => 'm', '%d' => 'd', '%H' => 'H']), strtotime($value)),
                2
            );
        }

        $this->travelTo('2026-03-10 12:30:00');

        $acme = $this->organization('Acme');
        $globex = $this->organization('Globex');

        $this->submission($acme, 'cleared', ['created_at' => '2026-03-10 09:10:00']);
        $this->submission($globex, 'rejected', ['created_at' => '2026-03-10 09:40:00']);
        $this->submission($acme, 'reported', ['created_at' => '2026-03-10 09:50:00']);
        $this->submission($acme, 'rejected', ['created_at' => '2026-03-10 11:05:00']);
        $this->submission($acme, 'rejected', ['created_at' => '2026-03-09 11:00:00']);

        $response = $this->api()->getJson('/api/admin/dashboard/error-rates?period=unknown')
            ->assertOk()
            ->assertJsonPath('data.period', 'unknown');

        // Compared loosely: MySQL returns the conditional sums as strings.
        $this->assertEquals([
            ['hour' => '2026-03-10 09:00:00', 'total' => 3, 'rejected' => 1, 'successful' => 2, 'error_rate' => 33.33],
            ['hour' => '2026-03-10 11:00:00', 'total' => 1, 'rejected' => 1, 'successful' => 0, 'error_rate' => 100],
        ], $response->json('data.data'));

        $this->api()->getJson('/api/admin/dashboard/error-rates?period=1h')
            ->assertOk()
            ->assertJsonPath('data.period', '1h')
            ->assertJsonPath('data.data', []);

        $this->api()->getJson('/api/admin/dashboard/error-rates?period=7d')
            ->assertOk()
            ->assertJsonCount(3, 'data.data');
    }

    public function test_offline_queue_summarises_every_tenant(): void
    {
        $acme = $this->organization('Acme');
        $globex = $this->organization('Globex');

        $this->queueItem($acme, 'pending', ['queued_at' => '2026-01-03 10:00:00']);
        $this->queueItem($acme, 'pending', ['queued_at' => '2026-01-02 10:00:00']);
        $older = $this->queueItem($acme, 'failed', ['last_error' => 'first', 'attempts' => 3, 'updated_at' => '2026-01-01 10:00:00']);
        $this->queueItem($globex, 'pending', ['queued_at' => '2026-01-04 10:00:00']);
        $this->queueItem($globex, 'completed');
        $newer = $this->queueItem($globex, 'failed', ['last_error' => 'second', 'attempts' => 1, 'updated_at' => '2026-01-05 10:00:00']);

        $response = $this->api()->getJson('/api/admin/dashboard/offline-queue')
            ->assertOk()
            ->assertJsonPath('data.summary', ['pending' => 3, 'processing' => 0, 'completed' => 1, 'failed' => 2])
            ->assertJsonPath('data.organizations_affected', ['pending' => 2, 'failed' => 2])
            ->assertJsonPath('data.oldest_pending_at', '2026-01-02 10:00:00')
            ->assertJsonPath('data.recent_failures.0.id', $newer->id)
            ->assertJsonPath('data.recent_failures.1.id', $older->id)
            ->assertJsonPath('data.recent_failures.0.last_error', 'second');

        $this->assertSame(
            ['id', 'invoice_id', 'org_id', 'last_error', 'attempts', 'updated_at'],
            array_keys($response->json('data.recent_failures.0'))
        );
    }

    public function test_offline_queue_lists_one_org(): void
    {
        $acme = $this->organization('Acme');
        $globex = $this->organization('Globex');

        $failed = $this->queueItem($acme, 'failed', ['queued_at' => '2026-01-03 10:00:00']);
        $this->queueItem($acme, 'pending', ['queued_at' => '2026-01-02 10:00:00']);
        $this->queueItem($acme, 'processing', ['queued_at' => '2026-01-01 10:00:00']);
        $this->queueItem($acme, 'completed');
        $this->queueItem($globex, 'pending');

        $response = $this->api()->getJson("/api/admin/dashboard/offline-queue/{$acme->id}")
            ->assertOk()
            ->assertJsonCount(3, 'data.items')
            ->assertJsonPath('data.items.0.id', $failed->id)
            ->assertJsonPath('data.items.2.state', 'processing')
            ->assertJsonPath('data.status.org_id', $acme->id);

        $this->assertContains('signed_xml', array_keys($response->json('data.items.0')));
    }

    /**
     * The admin has no tenant of their own, so the status has to be counted
     * for the organization asked about rather than through the caller's scope.
     */
    public function test_org_queue_status_counts_that_org(): void
    {
        $acme = $this->organization('Acme');
        $globex = $this->organization('Globex');

        $this->queueItem($acme, 'pending');
        $this->queueItem($acme, 'failed');
        $this->queueItem($acme, 'completed');
        $this->queueItem($globex, 'pending');

        $token = $this->platformToken();

        $this->asRequest(fn () => $this->withToken($token)
            ->getJson("/api/admin/dashboard/offline-queue/{$acme->id}")
            ->assertOk()
            ->assertJsonPath('data.status.pending', 1)
            ->assertJsonPath('data.status.failed', 1)
            ->assertJsonPath('data.status.completed', 1)
            ->assertJsonCount(2, 'data.items'));
    }

    public function test_retry_resets_a_failed_item(): void
    {
        $item = $this->queueItem($this->organization('Acme'), 'failed', [
            'attempts' => 3,
            'last_error' => 'ZATCA unreachable',
        ]);

        $token = $this->platformToken();

        $this->asRequest(fn () => $this->withToken($token)
            ->postJson("/api/admin/dashboard/offline-queue/{$item->id}/retry")
            ->assertOk()
            ->assertJsonPath('message', 'Queue item reset for retry')
            ->assertJsonPath('data', ['queue_id' => $item->id, 'new_state' => 'pending']));

        $row = DB::table('offline_queue')->where('id', $item->id)->first();

        $this->assertSame('pending', $row->state);
        $this->assertEquals(0, $row->attempts);
        $this->assertNull($row->last_error);
        $this->assertNotNull($row->next_attempt_at);
    }

    public function test_retry_refuses_unknown_or_unfailed(): void
    {
        $pending = $this->queueItem($this->organization('Acme'), 'pending');
        $token = $this->platformToken();

        $this->asRequest(function () use ($pending, $token) {
            $this->withToken($token)
                ->postJson('/api/admin/dashboard/offline-queue/00000000-0000-0000-0000-000000000000/retry')
                ->assertNotFound()
                ->assertJsonPath('error.message', 'Queue item not found');

            $this->withToken($token)
                ->postJson("/api/admin/dashboard/offline-queue/{$pending->id}/retry")
                ->assertStatus(400)
                ->assertJsonPath('error.message', 'Only failed items can be retried');
        });
    }

    public function test_issues_flag_each_problem(): void
    {
        $acme = $this->organization('Acme');
        $invoice = $this->invoice($acme);

        $this->connectivity(['available' => false, 'reason' => 'ZATCA down']);
        app(CircuitBreaker::class)->forceState('zatca_api', CircuitBreaker::STATE_OPEN, 'test', 'test');

        for ($i = 0; $i < 101; $i++) {
            $this->queueItem($acme, 'pending', ['invoice_id' => $invoice->id]);
        }

        $this->queueItem($acme, 'failed', ['invoice_id' => $invoice->id]);

        for ($i = 0; $i < 11; $i++) {
            $this->submission($acme, 'rejected', ['invoice_id' => $invoice->id]);
        }

        $this->submission($acme, 'rejected', ['invoice_id' => $invoice->id, 'created_at' => now()->subDays(2)]);

        $this->api()->getJson('/api/admin/dashboard/issues')
            ->assertOk()
            ->assertJsonPath('data.count', 5)
            ->assertJsonPath('data.has_critical', true)
            ->assertJsonPath('data.issues', [
                ['type' => 'connectivity', 'severity' => 'critical', 'message' => 'ZATCA API is unavailable', 'details' => 'ZATCA down'],
                ['type' => 'circuit_breaker', 'severity' => 'critical', 'message' => 'Circuit breaker is open due to repeated failures'],
                ['type' => 'offline_queue', 'severity' => 'warning', 'message' => 'Offline queue has 101 pending items'],
                ['type' => 'failed_submissions', 'severity' => 'warning', 'message' => '1 failed items in offline queue'],
                ['type' => 'rejections', 'severity' => 'warning', 'message' => '11 rejections in the last 24 hours'],
            ]);
    }

    public function test_issues_empty_when_healthy(): void
    {
        $this->connectivity(['available' => true, 'reason' => null]);

        $this->api()->getJson('/api/admin/dashboard/issues')
            ->assertOk()
            ->assertJsonPath('data.issues', [])
            ->assertJsonPath('data.count', 0)
            ->assertJsonPath('data.has_critical', false)
            ->assertJsonStructure(['data' => ['checked_at']]);
    }

    public function test_logs_filter_and_limit(): void
    {
        $acme = $this->organization('Acme');
        $globex = $this->organization('Globex');

        $this->submission($acme, 'rejected', ['created_at' => '2026-01-01 10:00:00']);
        $newest = $this->submission($acme, 'rejected', ['created_at' => '2026-01-03 10:00:00', 'last_error_code' => 'E1']);
        $this->submission($acme, 'cleared', ['created_at' => '2026-01-04 10:00:00']);
        $this->submission($globex, 'rejected', ['created_at' => '2026-01-05 10:00:00']);

        $response = $this->api()->getJson("/api/admin/dashboard/logs?state=rejected&org_id={$acme->id}&limit=1")
            ->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.logs.0.id', $newest->id)
            ->assertJsonPath('data.logs.0.last_error_code', 'E1')
            ->assertJsonPath('data.filters', ['state' => 'rejected', 'org_id' => $acme->id]);

        $this->assertSame([
            'id', 'invoice_id', 'org_id', 'state', 'submission_type', 'clearance_status', 'reporting_status',
            'last_error_code', 'last_error', 'retry_count', 'created_at', 'completed_at',
        ], array_keys($response->json('data.logs.0')));

        $this->api()->getJson('/api/admin/dashboard/logs')
            ->assertOk()
            ->assertJsonPath('data.count', 4)
            ->assertJsonPath('data.logs.0.org_id', $globex->id)
            ->assertJsonPath('data.filters', ['state' => null, 'org_id' => null]);
    }

    private function api(): self
    {
        return $this->withToken($this->platformToken());
    }

    /**
     * Stand in for the live probe by seeding the verdict it caches.
     */
    private function connectivity(array $verdict): void
    {
        $key = (new ReflectionClassConstant(Connectivity::class, 'CACHE_KEY'))->getValue();

        Cache::put($key, $verdict + ['latency_ms' => null, 'checked_at' => now()->toIso8601String()], 600);
    }
}
