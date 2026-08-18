<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Prometheus Metrics Controller
 *
 * Exposes application metrics in Prometheus format for monitoring.
 * Access is enforced by the `metrics` middleware — see config/metrics.php.
 *
 * Each collector degrades independently: a subsystem that cannot be reached
 * omits its metrics rather than failing the scrape, so one broken dependency
 * does not blind the whole endpoint.
 */
class MetricsController extends Controller
{
    /**
     * Export metrics in Prometheus format
     */
    public function index(): Response
    {
        $metrics = $this->collectMetrics();
        $output = $this->formatPrometheus($metrics);

        return response($output, 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
        ]);
    }

    /**
     * Collect all application metrics
     */
    private function collectMetrics(): array
    {
        return [
            // Application info
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

            // HTTP metrics
            ...$this->getHttpMetrics(),

            // Invoice metrics
            ...$this->getInvoiceMetrics(),

            // ZATCA metrics
            ...$this->getZatcaMetrics(),

            // Queue metrics
            ...$this->getQueueMetrics(),

            // Database metrics
            ...$this->getDatabaseMetrics(),

            // Cache metrics
            ...$this->getCacheMetrics(),
        ];
    }

    /**
     * Get HTTP request metrics
     */
    private function getHttpMetrics(): array
    {
        $requestCount = Cache::get('metrics:http_requests_total', 0);
        $errorCount = Cache::get('metrics:http_errors_total', 0);

        return [
            'http_requests_total' => [
                'type' => 'counter',
                'help' => 'Total HTTP requests',
                'value' => $requestCount,
            ],
            'http_errors_total' => [
                'type' => 'counter',
                'help' => 'Total HTTP errors (4xx, 5xx)',
                'value' => $errorCount,
            ],
        ];
    }

    /**
     * Get invoice metrics
     */
    private function getInvoiceMetrics(): array
    {
        $metrics = [];

        try {
            // Total invoices by status
            $invoicesByStatus = DB::table('invoices')
                ->select('status', DB::raw('count(*) as count'))
                ->whereNull('deleted_at')
                ->groupBy('status')
                ->get();

            foreach ($invoicesByStatus as $row) {
                $metrics["invoices_total_{$row->status}"] = [
                    'type' => 'gauge',
                    'help' => "Total invoices with status {$row->status}",
                    'value' => $row->count,
                    'labels' => ['status' => $row->status],
                ];
            }

            // Invoices created today
            $todayCount = DB::table('invoices')
                ->whereDate('created_at', today())
                ->count();

            $metrics['invoices_created_today'] = [
                'type' => 'gauge',
                'help' => 'Invoices created today',
                'value' => $todayCount,
            ];

        } catch (\Throwable $e) {
            $this->logCollectorFailure('invoice', $e);
        }

        return $metrics;
    }

    /**
     * Get ZATCA submission metrics
     */
    private function getZatcaMetrics(): array
    {
        $metrics = [];

        try {
            // Submissions by status (last 24 hours)
            $submissions = DB::table('invoice_submissions')
                ->select('status', DB::raw('count(*) as count'))
                ->where('created_at', '>=', now()->subHours(24))
                ->groupBy('status')
                ->get();

            foreach ($submissions as $row) {
                $metrics["zatca_submissions_{$row->status}"] = [
                    'type' => 'gauge',
                    'help' => "ZATCA submissions with status {$row->status} (24h)",
                    'value' => $row->count,
                    'labels' => ['status' => $row->status],
                ];
            }

            // Average submission time
            $avgTime = Cache::get('metrics:zatca_submission_avg_ms', 0);
            $metrics['zatca_submission_duration_avg_ms'] = [
                'type' => 'gauge',
                'help' => 'Average ZATCA submission duration in milliseconds',
                'value' => $avgTime,
            ];

            // Circuit breaker state
            $circuitState = Cache::get('zatca:circuit_breaker:state', 'closed');
            $metrics['zatca_circuit_breaker_open'] = [
                'type' => 'gauge',
                'help' => 'ZATCA circuit breaker state (1=open, 0=closed)',
                'value' => $circuitState === 'open' ? 1 : 0,
            ];

            // Offline queue size
            $offlineQueueSize = DB::table('offline_queue')
                ->where('status', 'pending')
                ->count();

            $metrics['zatca_offline_queue_size'] = [
                'type' => 'gauge',
                'help' => 'Number of invoices in offline queue',
                'value' => $offlineQueueSize,
            ];

        } catch (\Throwable $e) {
            $this->logCollectorFailure('zatca', $e);
        }

        return $metrics;
    }

    /**
     * Get queue metrics
     */
    private function getQueueMetrics(): array
    {
        $metrics = [];

        try {
            // Queue sizes
            $queues = ['default', 'zatca-submissions', 'webhooks'];

            foreach ($queues as $queue) {
                $size = Redis::llen("queues:{$queue}") ?? 0;
                $metrics["queue_size_{$queue}"] = [
                    'type' => 'gauge',
                    'help' => "Size of {$queue} queue",
                    'value' => $size,
                    'labels' => ['queue' => $queue],
                ];
            }

            // Failed jobs
            $failedJobs = DB::table('failed_jobs')->count();
            $metrics['queue_failed_jobs_total'] = [
                'type' => 'gauge',
                'help' => 'Total failed jobs',
                'value' => $failedJobs,
            ];

        } catch (\Throwable $e) {
            // Reached when phpredis is absent as well as when Redis is down:
            // a missing extension raises Error, not Exception.
            $this->logCollectorFailure('queue', $e);
        }

        return $metrics;
    }

    /**
     * Get database metrics
     */
    private function getDatabaseMetrics(): array
    {
        $metrics = [];

        try {
            $start = microtime(true);
            DB::select('SELECT 1');
            $latency = (microtime(true) - $start) * 1000;

            $metrics['database_latency_ms'] = [
                'type' => 'gauge',
                'help' => 'Database query latency in milliseconds',
                'value' => round($latency, 2),
            ];

            $metrics['database_up'] = [
                'type' => 'gauge',
                'help' => 'Database availability (1=up, 0=down)',
                'value' => 1,
            ];

        } catch (\Throwable $e) {
            $this->logCollectorFailure('database', $e);

            $metrics['database_up'] = [
                'type' => 'gauge',
                'help' => 'Database availability (1=up, 0=down)',
                'value' => 0,
            ];
        }

        return $metrics;
    }

    /**
     * Get cache metrics
     */
    private function getCacheMetrics(): array
    {
        $metrics = [];

        try {
            $start = microtime(true);
            Cache::get('metrics:ping');
            $latency = (microtime(true) - $start) * 1000;

            $metrics['cache_latency_ms'] = [
                'type' => 'gauge',
                'help' => 'Cache query latency in milliseconds',
                'value' => round($latency, 2),
            ];

            $metrics['cache_up'] = [
                'type' => 'gauge',
                'help' => 'Cache availability (1=up, 0=down)',
                'value' => 1,
            ];

        } catch (\Throwable $e) {
            $this->logCollectorFailure('cache', $e);

            $metrics['cache_up'] = [
                'type' => 'gauge',
                'help' => 'Cache availability (1=up, 0=down)',
                'value' => 0,
            ];
        }

        return $metrics;
    }

    /**
     * Record that one collector could not gather its metrics.
     *
     * A scrape must not fail because a single dependency is unreachable, but a
     * silent skip is worse: the metric simply disappears and the endpoint looks
     * healthy. Logging keeps the omission diagnosable.
     */
    private function logCollectorFailure(string $collector, \Throwable $e): void
    {
        Log::debug('Metrics collector unavailable', [
            'collector' => $collector,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }

    /**
     * Format metrics in Prometheus exposition format
     */
    private function formatPrometheus(array $metrics): string
    {
        $output = [];

        foreach ($metrics as $name => $metric) {
            // Sanitize metric name
            $name = 'masaar_'.preg_replace('/[^a-zA-Z0-9_]/', '_', $name);

            // Add HELP line
            $output[] = "# HELP {$name} {$metric['help']}";

            // Add TYPE line
            $output[] = "# TYPE {$name} {$metric['type']}";

            // Add metric value with optional labels
            if (isset($metric['labels']) && ! empty($metric['labels'])) {
                $labelPairs = [];
                foreach ($metric['labels'] as $key => $value) {
                    $labelPairs[] = "{$key}=\"{$value}\"";
                }
                $labelStr = '{'.implode(',', $labelPairs).'}';
                $output[] = "{$name}{$labelStr} {$metric['value']}";
            } else {
                $output[] = "{$name} {$metric['value']}";
            }

            $output[] = ''; // Empty line between metrics
        }

        return implode("\n", $output);
    }
}
