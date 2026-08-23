<?php

declare(strict_types=1);

namespace App\Domains\Platform\Services;

use App\Domains\Compliance\Fatoora\Services\CertificateService;
use App\Domains\Compliance\Fatoora\Services\CircuitBreaker;
use App\Domains\Compliance\Fatoora\Services\CredentialStore;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * How the platform as a whole is doing.
 *
 * Counts across every tenant, and the health of the things a submission
 * depends on. Deliberately not tenant-scoped: these queries go through the
 * query builder rather than the models, because the answer to "how many
 * invoices are there" is the platform's, not one taxpayer's.
 */
class PlatformStatus
{
    public function __construct(
        private readonly CircuitBreaker $circuitBreaker,
        private readonly CredentialStore $credentials,
        private readonly CertificateService $certificates,
    ) {}

    /**
     * Platform-wide counts, for the dashboard overview.
     */
    public function overview(): array
    {
        return [
            'organizations' => $this->getOrganizationStats(),
            'invoices' => $this->getPlatformInvoiceStats(),
            'submissions' => $this->getPlatformSubmissionStats(),
            'system' => $this->getSystemHealth(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Whether the platform's dependencies are answering, and how well.
     */
    public function health(): array
    {
        $data = [
            'circuit_breaker' => $this->circuitBreaker->getMetrics('zatca_api'),
            'database' => $this->getDatabaseHealth(),
            'cache' => $this->getCacheHealth(),
            'queue' => $this->getQueueHealth(),
            'checked_at' => now()->toIso8601String(),
        ];

        $data['overall_status'] = $this->determineSystemHealth($data);

        return $data;
    }

    /**
     * Certificate details for every organization that has one, keyed by id.
     *
     * One decryption per organization. That is acceptable on a platform
     * dashboard and wrong on a request path; the credential store is the only
     * place certificates exist, so there is no index to count instead.
     *
     * @return Collection<string, array{serial_number: ?string, valid_from: string, valid_to: string, status: string}>
     */
    public function organizationsWithCertificate(): Collection
    {
        return DB::table('organizations')
            ->pluck('id')
            ->mapWithKeys(fn ($id) => [
                (string) $id => $this->certificates->details(
                    $this->credentials->certificate((string) $id)
                ),
            ])
            ->filter();
    }

    /**
     * Get organization statistics.
     */
    private function getOrganizationStats(): array
    {
        return [
            'total' => DB::table('organizations')->count(),
            'active' => DB::table('organizations')
                ->where('status', 'active')
                ->count(),
            // Counted from the credential store, which is where certificates
            // are. certificate_lineage was never written, so this was always 0.
            'with_certificate' => $this->organizationsWithCertificate()->count(),
        ];
    }

    /**
     * Get platform-wide invoice statistics.
     * Optimized: Single query with conditional aggregates instead of 3 separate queries.
     */
    private function getPlatformInvoiceStats(): array
    {
        $today = now()->startOfDay();
        $thisHour = now()->startOfHour();

        $stats = DB::table('invoices')
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as today,
                SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as this_hour
            ', [$today, $thisHour])
            ->first();

        return [
            'total' => (int) ($stats->total ?? 0),
            'today' => (int) ($stats->today ?? 0),
            'this_hour' => (int) ($stats->this_hour ?? 0),
        ];
    }

    /**
     * Get platform-wide submission statistics.
     * Optimized: Single query with conditional aggregates instead of 5 separate queries.
     */
    private function getPlatformSubmissionStats(): array
    {
        $stats = DB::table('invoice_submissions')
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN state = 'cleared' THEN 1 ELSE 0 END) as cleared,
                SUM(CASE WHEN state = 'reported' THEN 1 ELSE 0 END) as reported,
                SUM(CASE WHEN state = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN state IN ('pending_submission', 'submitted', 'queued') THEN 1 ELSE 0 END) as pending
            ")
            ->first();

        return [
            'total' => (int) ($stats->total ?? 0),
            'cleared' => (int) ($stats->cleared ?? 0),
            'reported' => (int) ($stats->reported ?? 0),
            'rejected' => (int) ($stats->rejected ?? 0),
            'pending' => (int) ($stats->pending ?? 0),
        ];
    }

    /**
     * Get system health summary.
     */
    private function getSystemHealth(): array
    {
        $circuitState = $this->circuitBreaker->getState('zatca_api');

        return [
            'circuit_breaker' => $circuitState,
            'queue_size' => DB::table('offline_queue')
                ->where('state', 'pending')
                ->count(),
        ];
    }

    /**
     * Get database health metrics.
     */
    private function getDatabaseHealth(): array
    {
        try {
            $start = microtime(true);
            DB::select('SELECT 1');
            $latency = round((microtime(true) - $start) * 1000, 2);

            return [
                'status' => 'healthy',
                'latency_ms' => $latency,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get cache health metrics.
     */
    private function getCacheHealth(): array
    {
        try {
            $testKey = 'health_check_'.uniqid();
            Cache::put($testKey, true, 10);
            $retrieved = Cache::get($testKey);
            Cache::forget($testKey);

            return [
                'status' => $retrieved === true ? 'healthy' : 'degraded',
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get queue health metrics.
     * Optimized: Single query with conditional aggregates instead of 2 separate queries.
     */
    private function getQueueHealth(): array
    {
        $thirtyMinutesAgo = now()->subMinutes(30);

        $stats = DB::table('offline_queue')
            ->where('state', 'pending')
            ->selectRaw('
                COUNT(*) as pending,
                SUM(CASE WHEN queued_at < ? THEN 1 ELSE 0 END) as stuck
            ', [$thirtyMinutesAgo])
            ->first();

        $pending = (int) ($stats->pending ?? 0);
        $stuck = (int) ($stats->stuck ?? 0);

        return [
            'pending' => $pending,
            'stuck' => $stuck,
            'status' => $stuck === 0 ? 'healthy' : ($stuck > 50 ? 'critical' : 'warning'),
        ];
    }

    /**
     * Determine overall system health.
     */
    private function determineSystemHealth(array $data): string
    {
        if (($data['database']['status'] ?? '') === 'unhealthy') {
            return 'critical';
        }

        if (($data['circuit_breaker']['state'] ?? 'closed') === 'open') {
            return 'critical';
        }

        if (($data['queue']['status'] ?? '') === 'critical') {
            return 'critical';
        }

        if (($data['cache']['status'] ?? '') === 'unhealthy') {
            return 'warning';
        }

        if (($data['queue']['status'] ?? '') === 'warning') {
            return 'warning';
        }

        return 'healthy';
    }
}
