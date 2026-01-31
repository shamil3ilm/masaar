<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Audit Query Service for Compliance Audits.
 *
 * Provides fast, indexed queries for:
 * - "Show all invoices signed with certificate X"
 * - "Reconstruct invoice state at time T"
 * - "All invoices in date range with status Y"
 * - Post-clearance state changes (audit-only, no hash modifications)
 *
 * Designed to answer audit queries in <10 minutes for 3-year retention period.
 */
class AuditQueryService
{
    /**
     * Get retention period for audit data (in years).
     */
    private function getRetentionYears(): int
    {
        return (int) config('zatca.policies.retention.audit_logs_years', 7);
    }

    /**
     * Query invoices by certificate.
     * "Show me every invoice signed with certificate X"
     *
     * @param string $certificateId Certificate fingerprint
     * @param string|null $organizationId Filter by organization
     * @param array $options Additional options (limit, offset, date_from, date_to)
     * @return array{invoices: array, total: int, certificate_info: ?array}
     */
    public function getInvoicesByCertificate(
        string $certificateId,
        ?string $organizationId = null,
        array $options = []
    ): array {
        $query = DB::table('hash_chain_history')
            ->where('certificate_id', $certificateId);

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        if (!empty($options['date_from'])) {
            $query->where('created_at', '>=', $options['date_from']);
        }

        if (!empty($options['date_to'])) {
            $query->where('created_at', '<=', $options['date_to']);
        }

        $total = $query->count();

        $invoices = $query
            ->orderBy('icv', 'asc')
            ->offset($options['offset'] ?? 0)
            ->limit($options['limit'] ?? 1000)
            ->get()
            ->map(fn($row) => [
                'invoice_id' => $row->invoice_id,
                'icv' => $row->icv,
                'invoice_hash' => $row->invoice_hash,
                'previous_hash' => $row->previous_hash,
                'signed_at' => $row->created_at,
                'had_certificate_transition' => $row->certificate_transition !== null,
            ])
            ->toArray();

        // Get certificate info
        $certInfo = DB::table('certificate_lineage')
            ->where('certificate_id', $certificateId)
            ->first();

        return [
            'certificate_id' => $certificateId,
            'invoices' => $invoices,
            'total' => $total,
            'certificate_info' => $certInfo ? [
                'serial' => $certInfo->certificate_serial,
                'issuer' => $certInfo->issuer,
                'status' => $certInfo->status,
                'valid_from' => $certInfo->valid_from,
                'valid_to' => $certInfo->valid_to,
                'first_icv' => $certInfo->first_icv,
                'last_icv' => $certInfo->last_icv,
            ] : null,
        ];
    }

    /**
     * Reconstruct complete invoice state for audit.
     * Returns all data needed to verify the invoice against ZATCA.
     *
     * @param string $invoiceId
     * @return array|null Complete invoice audit record
     */
    public function reconstructInvoiceState(string $invoiceId): ?array
    {
        $invoice = DB::table('invoices')
            ->where('id', $invoiceId)
            ->first();

        if (!$invoice) {
            return null;
        }

        // Get hash chain entry
        $hashEntry = DB::table('hash_chain_history')
            ->where('invoice_id', $invoiceId)
            ->first();

        // Get all submissions
        $submissions = DB::table('invoice_submissions')
            ->where('invoice_id', $invoiceId)
            ->orderBy('submitted_at', 'asc')
            ->get()
            ->map(fn($s) => [
                'id' => $s->id,
                'status' => $s->status,
                'clearance_state' => $s->clearance_state ?? 'unknown',
                'submitted_at' => $s->submitted_at,
                'zatca_response' => $s->zatca_response ? json_decode($s->zatca_response, true) : null,
            ])
            ->toArray();

        // Get state change log
        $stateLog = DB::table('submission_state_logs')
            ->whereIn('submission_id', collect($submissions)->pluck('id'))
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn($l) => [
                'from_state' => $l->from_state,
                'to_state' => $l->to_state,
                'reason' => $l->reason,
                'actor' => $l->actor,
                'created_at' => $l->created_at,
            ])
            ->toArray();

        // Get certificate used
        $certInfo = null;
        if ($hashEntry) {
            $certInfo = DB::table('certificate_lineage')
                ->where('certificate_id', $hashEntry->certificate_id)
                ->first();
        }

        // Get offline queue history if any
        $offlineHistory = DB::table('offline_queue')
            ->where('invoice_id', $invoiceId)
            ->orderBy('queued_at', 'asc')
            ->get()
            ->toArray();

        return [
            'invoice' => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'type' => $invoice->type,
                'document_type' => $invoice->document_type,
                'status' => $invoice->status,
                'issue_date' => $invoice->issue_date,
                'total' => $invoice->total,
                'tax_amount' => $invoice->tax_amount,
                'buyer_name' => $invoice->buyer_name,
                'buyer_vat_number' => $invoice->buyer_vat_number,
            ],
            'compliance' => [
                'icv' => $invoice->icv,
                'hash' => $invoice->hash,
                'qr_code' => $invoice->qr_code,
                'signed_xml_present' => !empty($invoice->signed_xml),
                'signed_xml_length' => $invoice->signed_xml ? strlen($invoice->signed_xml) : 0,
            ],
            'hash_chain' => $hashEntry ? [
                'invoice_hash' => $hashEntry->invoice_hash,
                'previous_hash' => $hashEntry->previous_hash,
                'certificate_id' => $hashEntry->certificate_id,
                'certificate_transition' => $hashEntry->certificate_transition
                    ? json_decode($hashEntry->certificate_transition, true)
                    : null,
                'created_at' => $hashEntry->created_at,
            ] : null,
            'certificate' => $certInfo ? [
                'certificate_id' => $certInfo->certificate_id,
                'serial' => $certInfo->certificate_serial,
                'issuer' => $certInfo->issuer,
                'status' => $certInfo->status,
                'valid_from' => $certInfo->valid_from,
                'valid_to' => $certInfo->valid_to,
            ] : null,
            'submissions' => $submissions,
            'state_changes' => $stateLog,
            'offline_queue_history' => $offlineHistory,
            'audit_metadata' => [
                'reconstructed_at' => now()->toIso8601String(),
                'retention_expires_at' => now()->addYears($this->getRetentionYears())->toIso8601String(),
            ],
        ];
    }

    /**
     * Record post-clearance status update.
     * ZATCA may change status after initial clearance (delayed reconciliation).
     * This is audit-only - no hash modifications allowed.
     *
     * @param string $invoiceId
     * @param string $newState
     * @param array $zatcaUpdate
     * @param string $source
     */
    public function recordPostClearanceUpdate(
        string $invoiceId,
        string $newState,
        array $zatcaUpdate,
        string $source = 'zatca_reconciliation'
    ): void {
        $invoice = DB::table('invoices')->where('id', $invoiceId)->first();
        if (!$invoice) {
            Log::warning('Post-clearance update for unknown invoice', [
                'invoice_id' => $invoiceId,
                'new_state' => $newState,
            ]);
            return;
        }

        // Get the latest submission
        $submission = DB::table('invoice_submissions')
            ->where('invoice_id', $invoiceId)
            ->orderBy('submitted_at', 'desc')
            ->first();

        if ($submission) {
            $oldState = $submission->clearance_state ?? 'unknown';

            // Only record if state actually changed
            if ($oldState !== $newState) {
                // Update submission
                DB::table('invoice_submissions')
                    ->where('id', $submission->id)
                    ->update([
                        'clearance_state' => $newState,
                        'clearance_confirmed_at' => now(),
                        'updated_at' => now(),
                    ]);

                // Log state change
                DB::table('submission_state_logs')->insert([
                    'id' => \Illuminate\Support\Str::uuid()->toString(),
                    'submission_id' => $submission->id,
                    'from_state' => $oldState,
                    'to_state' => $newState,
                    'reason' => 'Post-clearance update from ' . $source,
                    'metadata' => json_encode($zatcaUpdate),
                    'actor' => 'system:' . $source,
                    'created_at' => now(),
                ]);

                Log::info('Post-clearance update recorded', [
                    'invoice_id' => $invoiceId,
                    'submission_id' => $submission->id,
                    'old_state' => $oldState,
                    'new_state' => $newState,
                    'source' => $source,
                ]);
            }
        }
    }

    /**
     * Query invoices by date range and status.
     */
    public function getInvoicesByDateRange(
        string $organizationId,
        string $dateFrom,
        string $dateTo,
        ?string $status = null,
        ?string $clearanceState = null,
        int $limit = 1000,
        int $offset = 0
    ): array {
        $query = DB::table('invoices')
            ->where('organization_id', $organizationId)
            ->whereBetween('issue_date', [$dateFrom, $dateTo]);

        if ($status) {
            $query->where('status', $status);
        }

        if ($clearanceState) {
            $query->whereExists(function ($subquery) use ($clearanceState) {
                $subquery->select(DB::raw(1))
                    ->from('invoice_submissions')
                    ->whereColumn('invoice_submissions.invoice_id', 'invoices.id')
                    ->where('invoice_submissions.clearance_state', $clearanceState);
            });
        }

        $total = $query->count();

        $invoices = $query
            ->orderBy('issue_date', 'asc')
            ->orderBy('icv', 'asc')
            ->offset($offset)
            ->limit($limit)
            ->get();

        return [
            'invoices' => $invoices,
            'total' => $total,
            'date_range' => ['from' => $dateFrom, 'to' => $dateTo],
            'filters' => ['status' => $status, 'clearance_state' => $clearanceState],
        ];
    }

    /**
     * Get hash chain integrity report for audit.
     */
    public function getHashChainIntegrityReport(string $organizationId): array
    {
        $history = DB::table('hash_chain_history')
            ->where('organization_id', $organizationId)
            ->orderBy('icv', 'asc')
            ->get();

        $errors = [];
        $certificateTransitions = [];
        $previousHash = 'NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==';
        $previousIcv = 0;

        foreach ($history as $entry) {
            // Check ICV sequence
            if ($entry->icv !== $previousIcv + 1) {
                $errors[] = [
                    'type' => 'icv_gap',
                    'expected_icv' => $previousIcv + 1,
                    'actual_icv' => $entry->icv,
                    'invoice_id' => $entry->invoice_id,
                    'at' => $entry->created_at,
                ];
            }

            // Check hash chain
            if ($entry->previous_hash !== $previousHash) {
                $errors[] = [
                    'type' => 'hash_chain_break',
                    'icv' => $entry->icv,
                    'invoice_id' => $entry->invoice_id,
                    'expected_previous' => substr($previousHash, 0, 20) . '...',
                    'actual_previous' => substr($entry->previous_hash, 0, 20) . '...',
                    'at' => $entry->created_at,
                ];
            }

            // Track certificate transitions
            if ($entry->certificate_transition) {
                $certificateTransitions[] = [
                    'icv' => $entry->icv,
                    'invoice_id' => $entry->invoice_id,
                    'transition' => json_decode($entry->certificate_transition, true),
                    'at' => $entry->created_at,
                ];
            }

            $previousHash = $entry->invoice_hash;
            $previousIcv = $entry->icv;
        }

        return [
            'organization_id' => $organizationId,
            'total_invoices' => count($history),
            'chain_valid' => empty($errors),
            'errors' => $errors,
            'certificate_transitions' => $certificateTransitions,
            'first_icv' => $history->first()?->icv,
            'last_icv' => $history->last()?->icv,
            'verified_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Export audit data for external review.
     * Returns data in a format suitable for auditors.
     */
    public function exportAuditData(
        string $organizationId,
        string $dateFrom,
        string $dateTo
    ): array {
        $invoices = $this->getInvoicesByDateRange($organizationId, $dateFrom, $dateTo);

        $exportData = [
            'export_metadata' => [
                'organization_id' => $organizationId,
                'date_range' => ['from' => $dateFrom, 'to' => $dateTo],
                'exported_at' => now()->toIso8601String(),
                'total_invoices' => $invoices['total'],
            ],
            'chain_integrity' => $this->getHashChainIntegrityReport($organizationId),
            'certificates_used' => DB::table('certificate_lineage')
                ->where('organization_id', $organizationId)
                ->get()
                ->toArray(),
            'invoices' => [],
        ];

        foreach ($invoices['invoices'] as $invoice) {
            $exportData['invoices'][] = $this->reconstructInvoiceState($invoice->id);
        }

        return $exportData;
    }
}
