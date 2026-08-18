<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Partition Maintenance Command.
 *
 * Manages table partitions for high-volume compliance tables.
 * Creates new partitions ahead of time and archives old ones.
 *
 * Should be scheduled to run monthly:
 * - Creates next month's partition
 * - Optionally archives/detaches old partitions (>7 years for compliance)
 *
 * @see docs/PRODUCTION-READINESS.md Section 8.8: Partitioning Strategy
 */
class PartitionMaintenance extends Command
{
    protected $signature = 'compliance:partition-maintenance
                            {--create-future : Create future partitions (next month)}
                            {--months-ahead=2 : How many months ahead to create}
                            {--check : Check partition status only, no changes}
                            {--archive-threshold=84 : Archive partitions older than N months (84 = 7 years)}
                            {--table= : Specific table to manage (default: all partitioned tables)}';

    protected $description = 'Manage table partitions for compliance tables';

    /**
     * Tables that should be partitioned.
     */
    private const PARTITIONED_TABLES = [
        'audit_logs' => 'created_at',
        'hash_chain_history' => 'created_at',
        'environment_variance_log' => 'created_at',
    ];

    /**
     * Minimum row count before considering partitioning.
     */
    private const PARTITION_THRESHOLD_ROWS = 10_000_000; // 10M rows

    public function handle(): int
    {
        $driver = DB::connection()->getDriverName();

        if ($driver !== 'pgsql') {
            $this->warn('Partition maintenance is only supported for PostgreSQL.');
            $this->info('For MySQL, use table partitioning DDL directly.');

            return 0;
        }

        $checkOnly = $this->option('check');
        $createFuture = $this->option('create-future');
        $monthsAhead = (int) $this->option('months-ahead');
        $archiveThreshold = (int) $this->option('archive-threshold');
        $specificTable = $this->option('table');

        $tables = $specificTable
            ? [$specificTable => self::PARTITIONED_TABLES[$specificTable] ?? 'created_at']
            : self::PARTITIONED_TABLES;

        $this->info('=== Partition Maintenance ===');
        $this->newLine();

        foreach ($tables as $table => $partitionColumn) {
            $this->processTable($table, $partitionColumn, [
                'check_only' => $checkOnly,
                'create_future' => $createFuture,
                'months_ahead' => $monthsAhead,
                'archive_threshold' => $archiveThreshold,
            ]);
            $this->newLine();
        }

        Log::info('Partition maintenance completed', [
            'tables' => array_keys($tables),
            'check_only' => $checkOnly,
            'create_future' => $createFuture,
        ]);

        return 0;
    }

    /**
     * Process a single table.
     */
    private function processTable(string $table, string $partitionColumn, array $options): void
    {
        $this->info("Table: {$table}");

        // Check if table is partitioned
        $isPartitioned = $this->isTablePartitioned($table);

        if (! $isPartitioned) {
            $rowCount = $this->getRowCount($table);
            $this->line('  Status: Not partitioned');
            $this->line('  Rows: '.number_format($rowCount));

            if ($rowCount >= self::PARTITION_THRESHOLD_ROWS) {
                $this->warn('  Recommendation: Table exceeds '.number_format(self::PARTITION_THRESHOLD_ROWS).' rows. Consider partitioning.');
                $this->line('  To partition, run migration with partition schema:');
                $this->line("    php artisan make:migration partition_{$table}_table");
            }

            return;
        }

        // Get existing partitions
        $partitions = $this->getPartitions($table);
        $this->line('  Status: Partitioned');
        $this->line('  Existing partitions: '.count($partitions));

        foreach ($partitions as $partition) {
            $this->line("    - {$partition['name']}: {$partition['range']}");
        }

        if ($options['check_only']) {
            return;
        }

        // Create future partitions
        if ($options['create_future']) {
            $this->createFuturePartitions($table, $partitionColumn, $options['months_ahead']);
        }

        // Archive old partitions
        $this->archiveOldPartitions($table, $partitions, $options['archive_threshold']);
    }

    /**
     * Check if a table is partitioned.
     */
    private function isTablePartitioned(string $table): bool
    {
        try {
            $result = DB::selectOne('
                SELECT COUNT(*) as count
                FROM pg_partitioned_table pt
                JOIN pg_class c ON c.oid = pt.partrelid
                WHERE c.relname = ?
            ', [$table]);

            return ($result->count ?? 0) > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get list of partitions for a table.
     */
    private function getPartitions(string $table): array
    {
        try {
            $results = DB::select('
                SELECT
                    c.relname as name,
                    pg_get_expr(c.relpartbound, c.oid) as range
                FROM pg_inherits i
                JOIN pg_class c ON c.oid = i.inhrelid
                JOIN pg_class p ON p.oid = i.inhparent
                WHERE p.relname = ?
                ORDER BY c.relname
            ', [$table]);

            return array_map(fn ($r) => ['name' => $r->name, 'range' => $r->range], $results);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Create future partitions.
     */
    private function createFuturePartitions(string $table, string $column, int $monthsAhead): void
    {
        $now = now();

        for ($i = 1; $i <= $monthsAhead; $i++) {
            $month = $now->copy()->addMonths($i);
            $partitionName = $table.'_'.$month->format('Y_m');
            $startDate = $month->startOfMonth()->toDateString();
            $endDate = $month->copy()->addMonth()->startOfMonth()->toDateString();

            // Check if partition already exists
            if ($this->partitionExists($partitionName)) {
                $this->line("  Partition {$partitionName} already exists");

                continue;
            }

            try {
                DB::statement("
                    CREATE TABLE IF NOT EXISTS {$partitionName}
                    PARTITION OF {$table}
                    FOR VALUES FROM ('{$startDate}') TO ('{$endDate}')
                ");

                $this->info("  Created partition: {$partitionName}");

                Log::info('Created partition', [
                    'table' => $table,
                    'partition' => $partitionName,
                    'range' => "{$startDate} to {$endDate}",
                ]);
            } catch (\Exception $e) {
                $this->error("  Failed to create partition {$partitionName}: ".$e->getMessage());
            }
        }
    }

    /**
     * Archive old partitions beyond retention threshold.
     */
    private function archiveOldPartitions(string $table, array $partitions, int $thresholdMonths): void
    {
        $cutoffDate = now()->subMonths($thresholdMonths);
        $archivedCount = 0;

        foreach ($partitions as $partition) {
            // Parse partition date from name (format: table_YYYY_MM)
            if (! preg_match('/_(\d{4})_(\d{2})$/', $partition['name'], $matches)) {
                continue;
            }

            $partitionDate = Carbon::createFromDate((int) $matches[1], (int) $matches[2], 1);

            if ($partitionDate < $cutoffDate) {
                $this->warn("  Partition {$partition['name']} is beyond {$thresholdMonths} months threshold");
                $this->line('    Consider archiving to cold storage');

                // Note: We don't automatically drop partitions for compliance data
                // Just log for manual review

                Log::warning('Partition eligible for archival', [
                    'table' => $table,
                    'partition' => $partition['name'],
                    'age_months' => $cutoffDate->diffInMonths($partitionDate),
                    'threshold_months' => $thresholdMonths,
                ]);

                $archivedCount++;
            }
        }

        if ($archivedCount > 0) {
            $this->warn("  {$archivedCount} partition(s) eligible for archival review");
        }
    }

    /**
     * Check if a partition exists.
     */
    private function partitionExists(string $partitionName): bool
    {
        try {
            $result = DB::selectOne('
                SELECT COUNT(*) as count
                FROM pg_class
                WHERE relname = ?
            ', [$partitionName]);

            return ($result->count ?? 0) > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get row count for a table.
     */
    private function getRowCount(string $table): int
    {
        try {
            // Use estimate for large tables
            $result = DB::selectOne('
                SELECT reltuples::bigint as estimate
                FROM pg_class
                WHERE relname = ?
            ', [$table]);

            $estimate = (int) ($result->estimate ?? 0);

            // If estimate is 0 or small, get exact count
            if ($estimate < 10000) {
                return DB::table($table)->count();
            }

            return $estimate;
        } catch (\Exception $e) {
            return 0;
        }
    }
}
