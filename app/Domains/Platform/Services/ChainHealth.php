<?php

declare(strict_types=1);

namespace App\Domains\Platform\Services;

use Illuminate\Support\Facades\DB;

/**
 * How quickly the hash chain can still be read, across every tenant.
 *
 * hash_chain_history only grows. Reading its head is on every issuance path,
 * so latency is sampled here to show degradation before it becomes critical.
 * Cross-tenant by design, so it reads through the query builder.
 */
class ChainHealth
{
    private const SAMPLES = 5;

    private const P95_WARNING_MS = 50;

    private const P99_CRITICAL_MS = 200;

    /**
     * Sampled head-read latency, the configured limits, and the table's size
     * and oldest entry.
     *
     * @return array{samples: int, avg_ms: float, p95_ms: float, p99_ms: float, thresholds: array{p95_warning: int|float, p99_critical: int|float}, row_count: int, oldest_entry: ?string}
     */
    public function measure(): array
    {
        $samples = $this->sampleHeadReads();
        $p95Index = (int) floor(count($samples) * 0.95);
        $p99Index = (int) floor(count($samples) * 0.99);

        $thresholds = config('fatoora.hash_chain_monitoring');

        return [
            'samples' => count($samples),
            'avg_ms' => round(array_sum($samples) / count($samples), 2),
            'p95_ms' => round($samples[$p95Index] ?? end($samples), 2),
            'p99_ms' => round($samples[$p99Index] ?? end($samples), 2),
            'thresholds' => [
                'p95_warning' => $thresholds['p95_warning_ms'] ?? self::P95_WARNING_MS,
                'p99_critical' => $thresholds['p99_critical_ms'] ?? self::P99_CRITICAL_MS,
            ],
            'row_count' => DB::table('hash_chain_history')->count(),
            'oldest_entry' => DB::table('hash_chain_history')
                ->orderBy('created_at')
                ->value('created_at'),
        ];
    }

    /**
     * Critical past the p99 limit, warning past the p95 limit, else healthy.
     */
    public function status(array $metrics): string
    {
        if ($metrics['p99_ms'] > ($metrics['thresholds']['p99_critical'] ?? self::P99_CRITICAL_MS)) {
            return 'critical';
        }

        if ($metrics['p95_ms'] > ($metrics['thresholds']['p95_warning'] ?? self::P95_WARNING_MS)) {
            return 'warning';
        }

        return 'healthy';
    }

    /**
     * What to do about a status other than healthy.
     */
    public function recommendation(string $status): ?string
    {
        return $status !== 'healthy'
            ? 'Consider partitioning hash_chain_history table or adding indexes'
            : null;
    }

    /**
     * Milliseconds taken by each read of the chain head, fastest first.
     *
     * @return list<float>
     */
    private function sampleHeadReads(): array
    {
        $samples = [];

        for ($i = 0; $i < self::SAMPLES; $i++) {
            $start = microtime(true);

            DB::table('hash_chain_history')
                ->orderByDesc('icv')
                ->limit(1)
                ->first();

            $samples[] = (microtime(true) - $start) * 1000;
        }

        sort($samples);

        return $samples;
    }
}
