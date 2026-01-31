<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Hash Chain Anomaly Detector.
 *
 * Detects anomalies in hash chain integrity:
 * - Hash chain breaks (mismatched previous_hash)
 * - Post-clearance hash modifications
 * - ICV sequence violations
 * - Orphaned invoices (in chain but not in invoices table)
 * - Phantom invoices (in invoices but not in chain)
 *
 * CRITICAL: Hash chain integrity is fundamental to ZATCA compliance.
 * Any anomaly should trigger immediate investigation.
 */
class HashChainAnomalyDetector
{
    /**
     * Anomaly severity levels.
     */
    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_CRITICAL = 'critical';

    /**
     * Cache keys.
     */
    private const LAST_SCAN_KEY = 'hash_chain:last_scan:';
    private const ANOMALY_CACHE_KEY = 'hash_chain:anomalies:';

    /**
     * Run full anomaly detection for an organization.
     */
    public function runFullScan(string $organizationId): array
    {
        $startTime = microtime(true);

        $results = [
            'organization_id' => $organizationId,
            'scanned_at' => now()->toIso8601String(),
            'status' => 'healthy',
            'anomalies' => [],
            'statistics' => [],
        ];

        // Run all detection checks
        $checks = [
            'chain_breaks' => $this->detectChainBreaks($organizationId),
            'icv_violations' => $this->detectIcvViolations($organizationId),
            'post_clearance_modifications' => $this->detectPostClearanceModifications($organizationId),
            'orphaned_entries' => $this->detectOrphanedEntries($organizationId),
            'phantom_invoices' => $this->detectPhantomInvoices($organizationId),
            'hash_mismatches' => $this->detectHashMismatches($organizationId),
        ];

        foreach ($checks as $checkName => $checkResult) {
            if (!empty($checkResult['anomalies'])) {
                $results['anomalies'][$checkName] = $checkResult['anomalies'];

                // Upgrade status based on severity
                $maxSeverity = $this->getMaxSeverity($checkResult['anomalies']);
                if ($maxSeverity === self::SEVERITY_CRITICAL) {
                    $results['status'] = 'critical';
                } elseif ($maxSeverity === self::SEVERITY_WARNING && $results['status'] !== 'critical') {
                    $results['status'] = 'warning';
                }
            }

            $results['statistics'][$checkName] = [
                'checked' => $checkResult['checked'] ?? 0,
                'anomaly_count' => count($checkResult['anomalies'] ?? []),
            ];
        }

        $results['scan_duration_ms'] = round((microtime(true) - $startTime) * 1000, 2);

        // Cache results
        Cache::put(self::ANOMALY_CACHE_KEY . $organizationId, $results, 3600);
        Cache::put(self::LAST_SCAN_KEY . $organizationId, now()->toIso8601String(), 86400);

        // Log and alert if anomalies found
        if ($results['status'] !== 'healthy') {
            $this->handleAnomalies($results);
        }

        return $results;
    }

    /**
     * Detect breaks in the hash chain (previous_hash mismatches).
     */
    private function detectChainBreaks(string $organizationId): array
    {
        $chainHistory = DB::table('hash_chain_history')
            ->where('organization_id', $organizationId)
            ->orderBy('icv')
            ->get(['id', 'icv', 'invoice_hash', 'previous_hash', 'chain_hash', 'created_at']);

        $anomalies = [];
        $previousChainHash = null;

        foreach ($chainHistory as $entry) {
            // Skip gap markers
            if (str_starts_with($entry->invoice_hash, 'RESERVED_GAP_')) {
                $previousChainHash = $entry->chain_hash;
                continue;
            }

            if ($previousChainHash !== null && $entry->previous_hash !== $previousChainHash) {
                $anomalies[] = [
                    'type' => 'chain_break',
                    'severity' => self::SEVERITY_CRITICAL,
                    'icv' => $entry->icv,
                    'expected_previous' => $previousChainHash,
                    'actual_previous' => $entry->previous_hash,
                    'entry_id' => $entry->id,
                    'detected_at' => now()->toIso8601String(),
                ];
            }

            $previousChainHash = $entry->chain_hash;
        }

        return [
            'checked' => $chainHistory->count(),
            'anomalies' => $anomalies,
        ];
    }

    /**
     * Detect ICV sequence violations.
     */
    private function detectIcvViolations(string $organizationId): array
    {
        $invoices = DB::table('invoices')
            ->where('organization_id', $organizationId)
            ->whereNotNull('icv')
            ->orderBy('icv')
            ->get(['id', 'icv', 'created_at']);

        $anomalies = [];
        $previousIcv = 0;
        $seenIcvs = [];

        foreach ($invoices as $invoice) {
            // Check for duplicates
            if (isset($seenIcvs[$invoice->icv])) {
                $anomalies[] = [
                    'type' => 'duplicate_icv',
                    'severity' => self::SEVERITY_CRITICAL,
                    'icv' => $invoice->icv,
                    'invoice_ids' => [$seenIcvs[$invoice->icv], $invoice->id],
                    'detected_at' => now()->toIso8601String(),
                ];
            }
            $seenIcvs[$invoice->icv] = $invoice->id;

            // Check for backwards movement
            if ($invoice->icv < $previousIcv) {
                $anomalies[] = [
                    'type' => 'icv_backwards',
                    'severity' => self::SEVERITY_CRITICAL,
                    'icv' => $invoice->icv,
                    'previous_icv' => $previousIcv,
                    'invoice_id' => $invoice->id,
                    'detected_at' => now()->toIso8601String(),
                ];
            }

            // Note: ICV gaps are allowed (might be reserved)
            // Large gaps are suspicious but not necessarily anomalous

            $previousIcv = $invoice->icv;
        }

        return [
            'checked' => $invoices->count(),
            'anomalies' => $anomalies,
        ];
    }

    /**
     * Detect modifications to invoices after ZATCA clearance.
     */
    private function detectPostClearanceModifications(string $organizationId): array
    {
        // Get cleared invoices where hash doesn't match stored hash
        $clearedInvoices = DB::table('invoices as i')
            ->join('invoice_submissions as s', 'i.id', '=', 's.invoice_id')
            ->where('i.organization_id', $organizationId)
            ->whereIn('s.clearance_state', ['cleared', 'reported'])
            ->select(['i.id', 'i.hash as current_hash', 's.invoice_hash as cleared_hash', 's.cleared_at', 'i.updated_at'])
            ->get();

        $anomalies = [];

        foreach ($clearedInvoices as $invoice) {
            if ($invoice->current_hash !== $invoice->cleared_hash && $invoice->cleared_hash !== null) {
                $anomalies[] = [
                    'type' => 'post_clearance_modification',
                    'severity' => self::SEVERITY_CRITICAL,
                    'invoice_id' => $invoice->id,
                    'cleared_hash' => $invoice->cleared_hash,
                    'current_hash' => $invoice->current_hash,
                    'cleared_at' => $invoice->cleared_at,
                    'modified_at' => $invoice->updated_at,
                    'detected_at' => now()->toIso8601String(),
                ];
            }
        }

        return [
            'checked' => $clearedInvoices->count(),
            'anomalies' => $anomalies,
        ];
    }

    /**
     * Detect orphaned chain entries (in chain but invoice doesn't exist).
     */
    private function detectOrphanedEntries(string $organizationId): array
    {
        $orphaned = DB::table('hash_chain_history as h')
            ->leftJoin('invoices as i', function ($join) {
                $join->on('h.organization_id', '=', 'i.organization_id')
                    ->on('h.icv', '=', 'i.icv');
            })
            ->where('h.organization_id', $organizationId)
            ->whereNull('i.id')
            ->where('h.invoice_hash', 'not like', 'RESERVED_GAP_%')
            ->select(['h.id', 'h.icv', 'h.invoice_hash', 'h.created_at'])
            ->get();

        $anomalies = [];

        foreach ($orphaned as $entry) {
            $anomalies[] = [
                'type' => 'orphaned_chain_entry',
                'severity' => self::SEVERITY_WARNING,
                'chain_entry_id' => $entry->id,
                'icv' => $entry->icv,
                'invoice_hash' => $entry->invoice_hash,
                'chain_created_at' => $entry->created_at,
                'detected_at' => now()->toIso8601String(),
            ];
        }

        return [
            'checked' => DB::table('hash_chain_history')->where('organization_id', $organizationId)->count(),
            'anomalies' => $anomalies,
        ];
    }

    /**
     * Detect phantom invoices (exist but not in hash chain).
     */
    private function detectPhantomInvoices(string $organizationId): array
    {
        $phantoms = DB::table('invoices as i')
            ->leftJoin('hash_chain_history as h', function ($join) {
                $join->on('i.organization_id', '=', 'h.organization_id')
                    ->on('i.icv', '=', 'h.icv');
            })
            ->where('i.organization_id', $organizationId)
            ->whereNotNull('i.icv')
            ->whereNotNull('i.hash')
            ->whereNull('h.id')
            ->select(['i.id', 'i.icv', 'i.hash', 'i.created_at'])
            ->get();

        $anomalies = [];

        foreach ($phantoms as $invoice) {
            $anomalies[] = [
                'type' => 'phantom_invoice',
                'severity' => self::SEVERITY_WARNING,
                'invoice_id' => $invoice->id,
                'icv' => $invoice->icv,
                'hash' => $invoice->hash,
                'created_at' => $invoice->created_at,
                'detected_at' => now()->toIso8601String(),
            ];
        }

        return [
            'checked' => DB::table('invoices')
                ->where('organization_id', $organizationId)
                ->whereNotNull('icv')
                ->count(),
            'anomalies' => $anomalies,
        ];
    }

    /**
     * Detect hash mismatches between stored and computed hashes.
     */
    private function detectHashMismatches(string $organizationId): array
    {
        // Get chain entries with their invoice data
        $entries = DB::table('hash_chain_history as h')
            ->join('invoices as i', function ($join) {
                $join->on('h.organization_id', '=', 'i.organization_id')
                    ->on('h.icv', '=', 'i.icv');
            })
            ->where('h.organization_id', $organizationId)
            ->select(['h.id as chain_id', 'h.icv', 'h.invoice_hash', 'i.id as invoice_id', 'i.hash'])
            ->get();

        $anomalies = [];

        foreach ($entries as $entry) {
            if ($entry->invoice_hash !== $entry->hash && $entry->hash !== null) {
                $anomalies[] = [
                    'type' => 'hash_mismatch',
                    'severity' => self::SEVERITY_CRITICAL,
                    'icv' => $entry->icv,
                    'invoice_id' => $entry->invoice_id,
                    'chain_hash' => $entry->invoice_hash,
                    'invoice_hash' => $entry->hash,
                    'detected_at' => now()->toIso8601String(),
                ];
            }
        }

        return [
            'checked' => $entries->count(),
            'anomalies' => $anomalies,
        ];
    }

    /**
     * Get maximum severity from anomaly list.
     */
    private function getMaxSeverity(array $anomalies): string
    {
        $hasCritical = false;
        $hasWarning = false;

        foreach ($anomalies as $anomaly) {
            if (($anomaly['severity'] ?? '') === self::SEVERITY_CRITICAL) {
                $hasCritical = true;
            } elseif (($anomaly['severity'] ?? '') === self::SEVERITY_WARNING) {
                $hasWarning = true;
            }
        }

        if ($hasCritical) return self::SEVERITY_CRITICAL;
        if ($hasWarning) return self::SEVERITY_WARNING;
        return self::SEVERITY_INFO;
    }

    /**
     * Handle detected anomalies (logging, alerting).
     */
    private function handleAnomalies(array $results): void
    {
        $totalAnomalies = 0;
        foreach ($results['anomalies'] as $anomalyList) {
            $totalAnomalies += count($anomalyList);
        }

        // Log based on severity
        if ($results['status'] === 'critical') {
            Log::critical('HASH CHAIN CRITICAL ANOMALIES DETECTED', [
                'organization_id' => $results['organization_id'],
                'anomaly_count' => $totalAnomalies,
                'anomaly_types' => array_keys($results['anomalies']),
            ]);
        } else {
            Log::warning('Hash chain anomalies detected', [
                'organization_id' => $results['organization_id'],
                'anomaly_count' => $totalAnomalies,
                'anomaly_types' => array_keys($results['anomalies']),
            ]);
        }

        // Store in audit log
        DB::table('audit_logs')->insert([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'auditable_type' => 'HashChain',
            'auditable_id' => $results['organization_id'],
            'event' => 'anomaly_detected',
            'old_values' => json_encode([]),
            'new_values' => json_encode([
                'status' => $results['status'],
                'anomaly_count' => $totalAnomalies,
                'anomaly_summary' => array_map(fn($a) => count($a), $results['anomalies']),
            ]),
            'user_id' => null,
            'user_type' => null,
            'ip_address' => null,
            'user_agent' => 'HashChainAnomalyDetector',
            'tags' => json_encode(['hash_chain', 'anomaly', $results['status']]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Quick health check (subset of full scan).
     */
    public function quickHealthCheck(string $organizationId): array
    {
        // Just check the most recent entries
        $recentEntries = DB::table('hash_chain_history')
            ->where('organization_id', $organizationId)
            ->orderByDesc('icv')
            ->limit(10)
            ->get();

        $healthy = true;
        $previousChainHash = null;

        // Check chain continuity for recent entries (in reverse)
        $entries = $recentEntries->sortBy('icv');
        foreach ($entries as $entry) {
            if ($previousChainHash !== null && $entry->previous_hash !== $previousChainHash) {
                $healthy = false;
                break;
            }
            $previousChainHash = $entry->chain_hash;
        }

        // Check for recent ICV duplicates
        $recentIcvs = DB::table('invoices')
            ->where('organization_id', $organizationId)
            ->orderByDesc('icv')
            ->limit(20)
            ->pluck('icv');

        $hasDuplicates = $recentIcvs->count() !== $recentIcvs->unique()->count();

        return [
            'organization_id' => $organizationId,
            'healthy' => $healthy && !$hasDuplicates,
            'checked_at' => now()->toIso8601String(),
            'last_full_scan' => Cache::get(self::LAST_SCAN_KEY . $organizationId),
            'recommendation' => (!$healthy || $hasDuplicates)
                ? 'Run full scan immediately'
                : 'Healthy - full scan not urgent',
        ];
    }

    /**
     * Get cached anomaly results.
     */
    public function getCachedResults(string $organizationId): ?array
    {
        return Cache::get(self::ANOMALY_CACHE_KEY . $organizationId);
    }

    /**
     * Verify integrity of a specific invoice in the chain.
     */
    public function verifyInvoiceIntegrity(string $invoiceId): array
    {
        $invoice = DB::table('invoices')->where('id', $invoiceId)->first();

        if (!$invoice) {
            return ['valid' => false, 'error' => 'Invoice not found'];
        }

        $chainEntry = DB::table('hash_chain_history')
            ->where('organization_id', $invoice->organization_id)
            ->where('icv', $invoice->icv)
            ->first();

        if (!$chainEntry) {
            return [
                'valid' => false,
                'error' => 'Invoice not found in hash chain',
                'invoice_id' => $invoiceId,
                'icv' => $invoice->icv,
            ];
        }

        // Verify hash matches
        $hashMatches = $invoice->hash === $chainEntry->invoice_hash;

        // Verify chain continuity
        $previousEntry = DB::table('hash_chain_history')
            ->where('organization_id', $invoice->organization_id)
            ->where('icv', $invoice->icv - 1)
            ->first();

        $chainContinuity = true;
        if ($previousEntry && $chainEntry->previous_hash !== $previousEntry->chain_hash) {
            $chainContinuity = false;
        }

        return [
            'valid' => $hashMatches && $chainContinuity,
            'invoice_id' => $invoiceId,
            'icv' => $invoice->icv,
            'hash_matches' => $hashMatches,
            'chain_continuity' => $chainContinuity,
            'chain_entry' => [
                'id' => $chainEntry->id,
                'invoice_hash' => $chainEntry->invoice_hash,
                'previous_hash' => $chainEntry->previous_hash,
                'chain_hash' => $chainEntry->chain_hash,
            ],
            'verified_at' => now()->toIso8601String(),
        ];
    }
}
