<?php

declare(strict_types=1);

namespace App\Domains\Platform\Services;

use App\Domains\Compliance\Countries\SaudiArabia\Filing\CircuitBreaker;
use App\Domains\Platform\Support\QueueConnections;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * Gathers the figures /api/metrics exposes to Prometheus.
 *
 * One set of figures for the whole deployment, so the queries span every
 * tenant through the query builder.
 *
 * Each collector degrades independently: a subsystem that cannot be reached
 * omits its metrics rather than failing the scrape, so one broken dependency
 * does not blind the whole endpoint.
 */
class MetricsCollector
{
    /**
     * Job queues whose depth is reported, on whichever queue driver runs.
     */
    private const QUEUES = ['default', 'zatca-submissions', 'webhooks'];

    public function __construct(
        private readonly CircuitBreaker $circuitBreaker,
    ) {}

    /**
     * Every metric, keyed by name, each with its type, help text, value and
     * optional labels.
     *
     * @return array<string, array{type: string, help: string, value: int|float, labels?: array<string, string>}>
     */
    public function collect(): array
    {
        return [
            'app_info' => [
                'type' => 'gauge',
                'help' => 'Application information',
                'value' => 1,
                'labels' => [
                    'version' => config('app.version', '1.0.0'),
                    'environment' => config('app.env'),
                    'php_version' => PHP_VERSION,
                ],
            ],
            ...$this->httpMetrics(),
            ...$this->invoiceMetrics(),
            ...$this->zatcaMetrics(),
            ...$this->queueMetrics(),
            ...$this->databaseMetrics(),
            ...$this->cacheMetrics(),
        ];
    }

    private function httpMetrics(): array
    {
        return [
            'http_requests_total' => [
                'type' => 'counter',
                'help' => 'Total HTTP requests',
                'value' => Cache::get('metrics:http_requests_total', 0),
            ],
            'http_errors_total' => [
                'type' => 'counter',
                'help' => 'Total HTTP errors (4xx, 5xx)',
                'value' => Cache::get('metrics:http_errors_total', 0),
            ],
        ];
    }

    /**
     * Invoices by status, and how many were created today.
     */
    private function invoiceMetrics(): array
    {
        $metrics = [];

        try {
            $byStatus = DB::table('invoices')
                ->selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->get();

            foreach ($byStatus as $row) {
                $metrics["invoices_total_{$row->status}"] = [
                    'type' => 'gauge',
                    'help' => "Total invoices with status {$row->status}",
                    'value' => $row->count,
                    'labels' => ['status' => $row->status],
                ];
            }

            $metrics['invoices_created_today'] = [
                'type' => 'gauge',
                'help' => 'Invoices created today',
                'value' => DB::table('invoices')->whereDate('created_at', today())->count(),
            ];
        } catch (\Throwable $e) {
            $this->logFailure('invoice', $e);
        }

        return $metrics;
    }

    /**
     * Submissions by state over the last day, submission timing, the
     * circuit breaker and the offline backlog.
     */
    private function zatcaMetrics(): array
    {
        $metrics = [];

        try {
            $byState = DB::table('invoice_submissions')
                ->selectRaw('state, count(*) as count')
                ->where('created_at', '>=', now()->subHours(24))
                ->groupBy('state')
                ->get();

            foreach ($byState as $row) {
                $metrics["zatca_submissions_{$row->state}"] = [
                    'type' => 'gauge',
                    'help' => "Tax authority submissions with status {$row->state} (24h)",
                    'value' => $row->count,
                    'labels' => ['status' => $row->state],
                ];
            }

            $metrics['zatca_submission_duration_avg_ms'] = [
                'type' => 'gauge',
                'help' => 'Average tax authority submission duration in milliseconds',
                'value' => Cache::get('metrics:zatca_submission_avg_ms', 0),
            ];

            $metrics['zatca_circuit_breaker_open'] = [
                'type' => 'gauge',
                'help' => 'Tax authority circuit breaker state (1=open, 0=closed)',
                'value' => $this->circuitBreaker->getState('zatca_api') === CircuitBreaker::STATE_OPEN ? 1 : 0,
            ];

            $metrics['zatca_offline_queue_size'] = [
                'type' => 'gauge',
                'help' => 'Number of invoices in offline queue',
                'value' => DB::table('offline_queue')->where('state', 'pending')->count(),
            ];
        } catch (\Throwable $e) {
            $this->logFailure('zatca', $e);
        }

        return $metrics;
    }

    /**
     * Job queue depths and failed jobs.
     */
    private function queueMetrics(): array
    {
        $metrics = [];

        // Asked of the connection each queue is on, not of Redis: the depths
        // were read with Redis::llen whatever the driver, which on the
        // database queue - the default without Redis - threw on every scrape.
        // Filing may be on a connection of its own (QueueConnections).
        try {
            foreach (self::QUEUES as $queue) {
                $metrics["queue_size_{$queue}"] = [
                    'type' => 'gauge',
                    'help' => "Size of {$queue} queue",
                    'value' => Queue::connection(QueueConnections::for($queue))->size($queue),
                    'labels' => ['queue' => $queue],
                ];
            }
        } catch (\Throwable $e) {
            // A missing phpredis extension raises Error, not Exception.
            $this->logFailure('queue', $e);
        }

        // Apart, so a queue backend that is down does not also hide the jobs
        // that have already failed - which is when they matter most.
        try {
            $metrics['queue_failed_jobs_total'] = [
                'type' => 'gauge',
                'help' => 'Total failed jobs',
                'value' => DB::table('failed_jobs')->count(),
            ];
        } catch (\Throwable $e) {
            $this->logFailure('failed_jobs', $e);
        }

        return $metrics;
    }

    private function databaseMetrics(): array
    {
        try {
            $start = microtime(true);
            DB::select('SELECT 1');
            $latency = (microtime(true) - $start) * 1000;

            return [
                'database_latency_ms' => [
                    'type' => 'gauge',
                    'help' => 'Database query latency in milliseconds',
                    'value' => round($latency, 2),
                ],
                'database_up' => $this->availability('Database', 1),
            ];
        } catch (\Throwable $e) {
            $this->logFailure('database', $e);

            return ['database_up' => $this->availability('Database', 0)];
        }
    }

    private function cacheMetrics(): array
    {
        try {
            $start = microtime(true);
            Cache::get('metrics:ping');
            $latency = (microtime(true) - $start) * 1000;

            return [
                'cache_latency_ms' => [
                    'type' => 'gauge',
                    'help' => 'Cache query latency in milliseconds',
                    'value' => round($latency, 2),
                ],
                'cache_up' => $this->availability('Cache', 1),
            ];
        } catch (\Throwable $e) {
            $this->logFailure('cache', $e);

            return ['cache_up' => $this->availability('Cache', 0)];
        }
    }

    private function availability(string $subsystem, int $up): array
    {
        return [
            'type' => 'gauge',
            'help' => "{$subsystem} availability (1=up, 0=down)",
            'value' => $up,
        ];
    }

    /**
     * Record that one collector could not gather its metrics.
     *
     * A scrape must not fail because a single dependency is unreachable, but a
     * silent skip is worse: the metric simply disappears and the endpoint looks
     * healthy. Logging keeps the omission diagnosable.
     */
    private function logFailure(string $collector, \Throwable $e): void
    {
        Log::debug('Metrics collector unavailable', [
            'collector' => $collector,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }
}
