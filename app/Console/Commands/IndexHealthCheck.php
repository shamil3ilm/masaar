<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Index Health Check Command.
 *
 * Monitors database index health for critical compliance tables.
 * Detects slow burn failures before they become outages.
 *
 * Tables monitored:
 * - hash_chain_history: Range scans by org+icv
 * - audit_logs: Time-range queries
 * - invoices: Complex filters
 * - invoice_submissions: Status lookups
 *
 * @see docs/PRODUCTION-READINESS.md Section 8: Index Health Monitoring
 */
class IndexHealthCheck extends Command
{
    protected $signature = 'compliance:index-health
                            {--table= : Specific table to check (optional)}
                            {--alert : Send alerts for critical issues}
                            {--json : Output results as JSON}';

    protected $description = 'Check database index health for critical compliance tables';

    /**
     * P95 warning thresholds in milliseconds.
     */
    private const P95_WARNING_THRESHOLDS = [
        'hash_chain_history' => 100,
        'audit_logs' => 200,
        'invoices' => 150,
        'invoice_submissions' => 100,
        'environment_variance_log' => 150,
    ];

    /**
     * P99 critical thresholds in milliseconds.
     */
    private const P99_CRITICAL_THRESHOLDS = [
        'hash_chain_history' => 500,
        'audit_logs' => 1000,
        'invoices' => 750,
        'invoice_submissions' => 500,
        'environment_variance_log' => 750,
    ];

    /**
     * Sequential scan threshold per hour.
     */
    private const SEQ_SCAN_WARNING = 1000;
    private const SEQ_SCAN_CRITICAL = 5000;

    /**
     * Table bloat thresholds (percentage).
     */
    private const TABLE_BLOAT_WARNING = 20;
    private const TABLE_BLOAT_CRITICAL = 50;

    /**
     * Index bloat thresholds (percentage).
     */
    private const INDEX_BLOAT_WARNING = 30;
    private const INDEX_BLOAT_CRITICAL = 60;

    public function handle(): int
    {
        $specificTable = $this->option('table');
        $sendAlerts = $this->option('alert');
        $jsonOutput = $this->option('json');

        $tables = $specificTable
            ? [$specificTable]
            : array_keys(self::P95_WARNING_THRESHOLDS);

        $results = [
            'checked_at' => now()->toIso8601String(),
            'tables' => [],
            'overall_status' => 'healthy',
            'alerts' => [],
        ];

        foreach ($tables as $table) {
            $tableResult = $this->checkTable($table);
            $results['tables'][$table] = $tableResult;

            if ($tableResult['status'] === 'critical') {
                $results['overall_status'] = 'critical';
                $results['alerts'][] = $tableResult['alert'];
            } elseif ($tableResult['status'] === 'warning' && $results['overall_status'] !== 'critical') {
                $results['overall_status'] = 'warning';
            }
        }

        // Get database-level stats
        $results['database'] = $this->getDatabaseStats();

        // Send alerts if requested
        if ($sendAlerts && !empty($results['alerts'])) {
            $this->sendAlerts($results['alerts']);
        }

        // Output results
        if ($jsonOutput) {
            $this->line(json_encode($results, JSON_PRETTY_PRINT));
        } else {
            $this->displayResults($results);
        }

        // Log for metrics collection
        Log::info('Index health check completed', [
            'overall_status' => $results['overall_status'],
            'tables_checked' => count($tables),
            'alerts_count' => count($results['alerts']),
        ]);

        return $results['overall_status'] === 'critical' ? 1 : 0;
    }

    /**
     * Check a specific table's index health.
     */
    private function checkTable(string $table): array
    {
        $result = [
            'table' => $table,
            'status' => 'healthy',
            'metrics' => [],
            'recommendations' => [],
        ];

        // Check if table exists
        if (!$this->tableExists($table)) {
            return [
                'table' => $table,
                'status' => 'skipped',
                'reason' => 'Table does not exist',
            ];
        }

        // Get table size
        $result['metrics']['row_count'] = $this->getRowCount($table);
        $result['metrics']['size_mb'] = $this->getTableSizeMb($table);

        // Run sample query and measure execution time
        $queryResult = $this->measureQueryPerformance($table);
        $result['metrics']['sample_query_ms'] = $queryResult['execution_time_ms'];
        $result['metrics']['used_index'] = $queryResult['used_index'];

        // Check against thresholds
        $p95Threshold = self::P95_WARNING_THRESHOLDS[$table] ?? 200;
        $p99Threshold = self::P99_CRITICAL_THRESHOLDS[$table] ?? 1000;

        if ($queryResult['execution_time_ms'] > $p99Threshold) {
            $result['status'] = 'critical';
            $result['alert'] = sprintf(
                'Critical: %s queries exceeding %dms (measured: %dms)',
                $table,
                $p99Threshold,
                $queryResult['execution_time_ms']
            );
            $result['recommendations'][] = 'Consider ANALYZE, VACUUM, or REINDEX';
            $result['recommendations'][] = 'Review query plan with EXPLAIN ANALYZE';
        } elseif ($queryResult['execution_time_ms'] > $p95Threshold) {
            $result['status'] = 'warning';
            $result['recommendations'][] = 'Query performance degrading - monitor closely';
            $result['recommendations'][] = 'Schedule ANALYZE during maintenance window';
        }

        // Check for missing index usage
        if (!$queryResult['used_index']) {
            $result['recommendations'][] = 'Query did not use index - verify index exists for this query pattern';
            if ($result['status'] === 'healthy') {
                $result['status'] = 'warning';
            }
        }

        return $result;
    }

    /**
     * Measure query performance for a table.
     */
    private function measureQueryPerformance(string $table): array
    {
        $driver = DB::connection()->getDriverName();

        // Define sample queries for each table
        $queries = [
            'hash_chain_history' => "SELECT * FROM {$table} WHERE created_at > ? LIMIT 100",
            'audit_logs' => "SELECT * FROM {$table} WHERE created_at > ? LIMIT 100",
            'invoices' => "SELECT * FROM {$table} WHERE created_at > ? LIMIT 100",
            'invoice_submissions' => "SELECT * FROM {$table} WHERE created_at > ? LIMIT 100",
            'environment_variance_log' => "SELECT * FROM {$table} WHERE created_at > ? LIMIT 100",
        ];

        $query = $queries[$table] ?? "SELECT * FROM {$table} LIMIT 100";
        $hasDateParam = str_contains($query, '?');
        $params = $hasDateParam ? [now()->subDay()->toDateTimeString()] : [];

        $startTime = microtime(true);

        try {
            if ($driver === 'pgsql') {
                // PostgreSQL: Use EXPLAIN ANALYZE
                $explainQuery = "EXPLAIN ANALYZE " . $query;
                $explainResult = DB::select($explainQuery, $params);
                $executionTime = $this->parsePostgresExecutionTime($explainResult);
                $usedIndex = $this->checkPostgresUsedIndex($explainResult);
            } elseif ($driver === 'mysql') {
                // MySQL: Use EXPLAIN and measure
                DB::select($query, $params);
                $executionTime = (microtime(true) - $startTime) * 1000;

                $explainResult = DB::select("EXPLAIN " . $query, $params);
                $usedIndex = $this->checkMysqlUsedIndex($explainResult);
            } else {
                // Fallback: Just measure
                DB::select($query, $params);
                $executionTime = (microtime(true) - $startTime) * 1000;
                $usedIndex = null;
            }
        } catch (\Exception $e) {
            return [
                'execution_time_ms' => -1,
                'used_index' => null,
                'error' => $e->getMessage(),
            ];
        }

        return [
            'execution_time_ms' => round($executionTime, 2),
            'used_index' => $usedIndex,
        ];
    }

    /**
     * Parse PostgreSQL EXPLAIN ANALYZE output for execution time.
     */
    private function parsePostgresExecutionTime(array $explainResult): float
    {
        foreach ($explainResult as $row) {
            $line = $row->{'QUERY PLAN'} ?? '';
            if (preg_match('/Execution Time: ([\d.]+) ms/', $line, $matches)) {
                return (float) $matches[1];
            }
        }
        return 0;
    }

    /**
     * Check if PostgreSQL query used an index.
     */
    private function checkPostgresUsedIndex(array $explainResult): bool
    {
        foreach ($explainResult as $row) {
            $line = $row->{'QUERY PLAN'} ?? '';
            if (str_contains($line, 'Index Scan') || str_contains($line, 'Index Only Scan')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if MySQL query used an index.
     */
    private function checkMysqlUsedIndex(array $explainResult): bool
    {
        foreach ($explainResult as $row) {
            $key = $row->key ?? null;
            if ($key !== null && $key !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if table exists.
     */
    private function tableExists(string $table): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable($table);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get row count for table.
     */
    private function getRowCount(string $table): int
    {
        try {
            return DB::table($table)->count();
        } catch (\Exception $e) {
            return -1;
        }
    }

    /**
     * Get table size in MB.
     */
    private function getTableSizeMb(string $table): float
    {
        $driver = DB::connection()->getDriverName();

        try {
            if ($driver === 'pgsql') {
                $result = DB::selectOne(
                    "SELECT pg_total_relation_size(?) / 1024 / 1024 AS size_mb",
                    [$table]
                );
                return round((float) ($result->size_mb ?? 0), 2);
            } elseif ($driver === 'mysql') {
                $database = DB::connection()->getDatabaseName();
                $result = DB::selectOne(
                    "SELECT ROUND((data_length + index_length) / 1024 / 1024, 2) AS size_mb
                     FROM information_schema.tables
                     WHERE table_schema = ? AND table_name = ?",
                    [$database, $table]
                );
                return (float) ($result->size_mb ?? 0);
            }
        } catch (\Exception $e) {
            return -1;
        }

        return -1;
    }

    /**
     * Get database-level statistics.
     */
    private function getDatabaseStats(): array
    {
        $driver = DB::connection()->getDriverName();
        $stats = ['driver' => $driver];

        try {
            if ($driver === 'pgsql') {
                // Get connection count
                $connections = DB::selectOne(
                    "SELECT count(*) AS count FROM pg_stat_activity WHERE state = 'active'"
                );
                $stats['active_connections'] = (int) ($connections->count ?? 0);

                // Get database size
                $size = DB::selectOne(
                    "SELECT pg_size_pretty(pg_database_size(current_database())) AS size"
                );
                $stats['database_size'] = $size->size ?? 'unknown';
            } elseif ($driver === 'mysql') {
                $connections = DB::selectOne("SHOW STATUS LIKE 'Threads_connected'");
                $stats['active_connections'] = (int) ($connections->Value ?? 0);
            }
        } catch (\Exception $e) {
            $stats['error'] = $e->getMessage();
        }

        return $stats;
    }

    /**
     * Send alerts for critical issues.
     */
    private function sendAlerts(array $alerts): void
    {
        foreach ($alerts as $alert) {
            Log::critical('Index Health Alert', ['message' => $alert]);

            // Could integrate with PagerDuty, Slack, etc.
            // For now, just log at critical level
        }

        $this->error('Alerts sent: ' . count($alerts));
    }

    /**
     * Display results in console format.
     */
    private function displayResults(array $results): void
    {
        $this->newLine();
        $this->info('=== Index Health Check Results ===');
        $this->line('Checked at: ' . $results['checked_at']);
        $this->newLine();

        foreach ($results['tables'] as $table => $data) {
            $statusColor = match ($data['status']) {
                'healthy' => 'green',
                'warning' => 'yellow',
                'critical' => 'red',
                default => 'gray',
            };

            $this->line("<fg={$statusColor}>[{$data['status']}]</> {$table}");

            if (isset($data['metrics'])) {
                $this->line("    Rows: " . number_format($data['metrics']['row_count'] ?? 0));
                $this->line("    Size: " . ($data['metrics']['size_mb'] ?? 'N/A') . " MB");
                $this->line("    Query time: " . ($data['metrics']['sample_query_ms'] ?? 'N/A') . " ms");
                $this->line("    Used index: " . ($data['metrics']['used_index'] ? 'Yes' : 'No'));
            }

            if (!empty($data['recommendations'])) {
                $this->line("    Recommendations:");
                foreach ($data['recommendations'] as $rec) {
                    $this->line("      - {$rec}");
                }
            }

            $this->newLine();
        }

        // Database stats
        if (isset($results['database'])) {
            $this->info('Database Stats:');
            foreach ($results['database'] as $key => $value) {
                $this->line("  {$key}: {$value}");
            }
        }

        // Overall status
        $this->newLine();
        $overallColor = match ($results['overall_status']) {
            'healthy' => 'green',
            'warning' => 'yellow',
            'critical' => 'red',
            default => 'white',
        };
        $this->line("<fg={$overallColor};options=bold>Overall Status: " . strtoupper($results['overall_status']) . "</>");

        if (!empty($results['alerts'])) {
            $this->newLine();
            $this->error('ALERTS:');
            foreach ($results['alerts'] as $alert) {
                $this->error("  - {$alert}");
            }
        }
    }
}
