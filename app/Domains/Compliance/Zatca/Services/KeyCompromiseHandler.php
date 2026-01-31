<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Key Compromise Handler.
 *
 * Manages certificate/key compromise scenarios with:
 * - Immediate revocation and halt of signing operations
 * - Identification of affected invoices
 * - Re-signing plan for pending/offline invoices
 * - Audit trail of compromise response
 *
 * CRITICAL: Key compromise is a P0 security incident requiring:
 * 1. Immediate halt of all signing with compromised key
 * 2. Notification to ZATCA (manual process)
 * 3. New certificate enrollment
 * 4. Assessment of affected invoices
 */
class KeyCompromiseHandler
{
    /**
     * Compromise states.
     */
    public const STATE_SUSPECTED = 'suspected';
    public const STATE_CONFIRMED = 'confirmed';
    public const STATE_CONTAINED = 'contained';
    public const STATE_REMEDIATED = 'remediated';

    /**
     * Cache key for compromised certificates.
     */
    private const COMPROMISED_CERTS_KEY = 'zatca:compromised_certs';

    /**
     * Report a suspected key compromise.
     *
     * @param string $organizationId
     * @param string $certificateHash SHA256 of the compromised certificate
     * @param string $reportedBy Who reported the compromise
     * @param string $reason Reason for suspecting compromise
     * @param \DateTimeInterface|null $compromisedSince Estimated compromise date
     * @return array Incident details
     */
    public function reportCompromise(
        string $organizationId,
        string $certificateHash,
        string $reportedBy,
        string $reason,
        ?\DateTimeInterface $compromisedSince = null
    ): array {
        $incidentId = 'KCI-' . strtoupper(bin2hex(random_bytes(8)));

        // Create incident record
        $incident = [
            'incident_id' => $incidentId,
            'organization_id' => $organizationId,
            'certificate_hash' => $certificateHash,
            'state' => self::STATE_SUSPECTED,
            'reported_by' => $reportedBy,
            'reason' => $reason,
            'compromised_since' => $compromisedSince?->format('Y-m-d H:i:s'),
            'reported_at' => now()->toIso8601String(),
            'actions_taken' => [],
        ];

        // Store incident
        DB::table('audit_logs')->insert([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'auditable_type' => 'KeyCompromise',
            'auditable_id' => $incidentId,
            'event' => 'compromise_reported',
            'old_values' => json_encode([]),
            'new_values' => json_encode($incident),
            'user_id' => null,
            'user_type' => null,
            'ip_address' => request()?->ip(),
            'user_agent' => $reportedBy,
            'tags' => json_encode(['security', 'key_compromise', 'critical']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Immediately halt signing with this certificate
        $this->haltSigning($organizationId, $certificateHash, $incidentId);

        // Log critical alert
        Log::critical('KEY COMPROMISE SUSPECTED', [
            'incident_id' => $incidentId,
            'organization_id' => $organizationId,
            'certificate_hash' => $certificateHash,
            'reported_by' => $reportedBy,
            'reason' => $reason,
        ]);

        return $incident;
    }

    /**
     * Confirm a suspected compromise.
     */
    public function confirmCompromise(string $incidentId, string $confirmedBy, string $evidence): array
    {
        // Get incident from audit log
        $log = DB::table('audit_logs')
            ->where('auditable_id', $incidentId)
            ->where('event', 'compromise_reported')
            ->first();

        if (!$log) {
            throw new \InvalidArgumentException("Incident {$incidentId} not found");
        }

        $incident = json_decode($log->new_values, true);
        $incident['state'] = self::STATE_CONFIRMED;
        $incident['confirmed_by'] = $confirmedBy;
        $incident['confirmed_at'] = now()->toIso8601String();
        $incident['evidence'] = $evidence;

        // Update certificate lineage
        DB::table('certificate_lineage')
            ->where('certificate_hash', $incident['certificate_hash'])
            ->update([
                'revoked_at' => now(),
                'revocation_reason' => 'key_compromise',
                'updated_at' => now(),
            ]);

        // Add to global compromised list
        $this->addToCompromisedList($incident['certificate_hash']);

        // Identify affected invoices
        $affected = $this->identifyAffectedInvoices($incident);
        $incident['affected_invoices'] = $affected;

        // Log confirmation
        DB::table('audit_logs')->insert([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'auditable_type' => 'KeyCompromise',
            'auditable_id' => $incidentId,
            'event' => 'compromise_confirmed',
            'old_values' => json_encode(['state' => self::STATE_SUSPECTED]),
            'new_values' => json_encode($incident),
            'user_id' => null,
            'user_type' => null,
            'ip_address' => request()?->ip(),
            'user_agent' => $confirmedBy,
            'tags' => json_encode(['security', 'key_compromise', 'confirmed', 'critical']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::critical('KEY COMPROMISE CONFIRMED', [
            'incident_id' => $incidentId,
            'affected_invoice_count' => count($affected['cleared'] ?? []) + count($affected['pending'] ?? []),
        ]);

        return $incident;
    }

    /**
     * Halt all signing operations for a certificate.
     */
    private function haltSigning(string $organizationId, string $certificateHash, string $incidentId): void
    {
        // Add to compromised certificates cache (checked by signing service)
        $compromised = Cache::get(self::COMPROMISED_CERTS_KEY, []);
        $compromised[$certificateHash] = [
            'organization_id' => $organizationId,
            'incident_id' => $incidentId,
            'halted_at' => now()->toIso8601String(),
        ];
        Cache::forever(self::COMPROMISED_CERTS_KEY, $compromised);

        // Update organization to prevent new invoices
        DB::table('organizations')
            ->where('id', $organizationId)
            ->update([
                'status' => 'suspended',
                'updated_at' => now(),
            ]);

        Log::warning('Signing halted for organization due to key compromise', [
            'organization_id' => $organizationId,
            'certificate_hash' => $certificateHash,
            'incident_id' => $incidentId,
        ]);
    }

    /**
     * Check if a certificate is compromised.
     */
    public function isCertificateCompromised(string $certificateHash): bool
    {
        $compromised = Cache::get(self::COMPROMISED_CERTS_KEY, []);
        return isset($compromised[$certificateHash]);
    }

    /**
     * Identify all invoices affected by the compromise.
     */
    public function identifyAffectedInvoices(array $incident): array
    {
        $organizationId = $incident['organization_id'];
        $certificateHash = $incident['certificate_hash'];
        $compromisedSince = $incident['compromised_since']
            ? \Carbon\Carbon::parse($incident['compromised_since'])
            : \Carbon\Carbon::now()->subYear(); // Default: assume 1 year if unknown

        // Find all invoices signed with this certificate after compromise date
        $invoicesQuery = DB::table('invoices')
            ->where('organization_id', $organizationId)
            ->where('created_at', '>=', $compromisedSince);

        // Get submissions to check which cert was used
        $submissions = DB::table('invoice_submissions')
            ->whereIn('invoice_id', $invoicesQuery->pluck('id'))
            ->get()
            ->groupBy('invoice_id');

        $affected = [
            'cleared' => [],      // Already cleared by ZATCA
            'pending' => [],      // Not yet submitted
            'in_offline_queue' => [], // In offline queue
            'total_count' => 0,
        ];

        foreach ($invoicesQuery->get() as $invoice) {
            $submission = ($submissions[$invoice->id] ?? collect())->first();

            $invoiceInfo = [
                'invoice_id' => $invoice->id,
                'icv' => $invoice->icv,
                'issue_date' => $invoice->issue_date,
                'total' => $invoice->total,
            ];

            if ($submission) {
                $clearanceState = $submission->clearance_state ?? 'unknown';
                if (in_array($clearanceState, ['cleared', 'reported'])) {
                    $affected['cleared'][] = $invoiceInfo;
                } else {
                    $affected['pending'][] = $invoiceInfo;
                }
            } else {
                // Check offline queue
                $inQueue = DB::table('offline_invoice_queue')
                    ->where('invoice_id', $invoice->id)
                    ->exists();

                if ($inQueue) {
                    $affected['in_offline_queue'][] = $invoiceInfo;
                } else {
                    $affected['pending'][] = $invoiceInfo;
                }
            }
        }

        $affected['total_count'] = count($affected['cleared'])
            + count($affected['pending'])
            + count($affected['in_offline_queue']);

        return $affected;
    }

    /**
     * Generate re-signing plan for pending invoices.
     *
     * Note: Already-cleared invoices CANNOT be re-signed.
     * They remain valid under the certificate at time of clearance.
     */
    public function generateResigningPlan(string $incidentId, string $newCertificateHash): array
    {
        $log = DB::table('audit_logs')
            ->where('auditable_id', $incidentId)
            ->where('event', 'compromise_confirmed')
            ->first();

        if (!$log) {
            throw new \InvalidArgumentException("Confirmed incident {$incidentId} not found");
        }

        $incident = json_decode($log->new_values, true);
        $affected = $incident['affected_invoices'] ?? [];

        $plan = [
            'incident_id' => $incidentId,
            'new_certificate_hash' => $newCertificateHash,
            'generated_at' => now()->toIso8601String(),
            'actions' => [],
        ];

        // Pending invoices need re-signing
        foreach ($affected['pending'] ?? [] as $invoice) {
            $plan['actions'][] = [
                'type' => 'resign',
                'invoice_id' => $invoice['invoice_id'],
                'priority' => 'high',
                'note' => 'Re-sign with new certificate before submission',
            ];
        }

        // Offline queue invoices need re-signing
        foreach ($affected['in_offline_queue'] ?? [] as $invoice) {
            $plan['actions'][] = [
                'type' => 'resign_and_requeue',
                'invoice_id' => $invoice['invoice_id'],
                'priority' => 'critical',
                'note' => 'Remove from queue, re-sign with new certificate, re-queue',
            ];
        }

        // Cleared invoices - no action possible, document only
        foreach ($affected['cleared'] ?? [] as $invoice) {
            $plan['actions'][] = [
                'type' => 'document_only',
                'invoice_id' => $invoice['invoice_id'],
                'priority' => 'info',
                'note' => 'Already cleared by ZATCA. Document in incident report. No re-signing possible.',
            ];
        }

        $plan['summary'] = [
            'invoices_to_resign' => count($affected['pending'] ?? []) + count($affected['in_offline_queue'] ?? []),
            'invoices_cleared_unchanged' => count($affected['cleared'] ?? []),
            'estimated_effort' => sprintf(
                '%d invoices requiring re-signing',
                count($affected['pending'] ?? []) + count($affected['in_offline_queue'] ?? [])
            ),
        ];

        // Store the plan
        DB::table('audit_logs')->insert([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'auditable_type' => 'KeyCompromise',
            'auditable_id' => $incidentId,
            'event' => 'resigning_plan_generated',
            'old_values' => json_encode([]),
            'new_values' => json_encode($plan),
            'user_id' => null,
            'user_type' => null,
            'ip_address' => request()?->ip(),
            'user_agent' => 'system',
            'tags' => json_encode(['security', 'key_compromise', 'remediation']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $plan;
    }

    /**
     * Execute re-signing for an invoice.
     */
    public function resignInvoice(
        string $invoiceId,
        string $incidentId,
        string $newCertificateHash
    ): array {
        // This would integrate with XadesSigner to re-sign
        // For now, mark as needing re-sign
        DB::table('invoices')
            ->where('id', $invoiceId)
            ->update([
                'signed_xml' => null, // Clear old signature
                'hash' => null,       // Will be recomputed
                'updated_at' => now(),
            ]);

        Log::info('Invoice marked for re-signing', [
            'invoice_id' => $invoiceId,
            'incident_id' => $incidentId,
            'new_certificate_hash' => $newCertificateHash,
        ]);

        return [
            'success' => true,
            'invoice_id' => $invoiceId,
            'action' => 'cleared_for_resign',
            'next_step' => 'Call signing service with new certificate',
        ];
    }

    /**
     * Mark incident as contained.
     */
    public function markContained(string $incidentId, string $containedBy, string $containmentActions): array
    {
        DB::table('audit_logs')->insert([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'auditable_type' => 'KeyCompromise',
            'auditable_id' => $incidentId,
            'event' => 'compromise_contained',
            'old_values' => json_encode(['state' => self::STATE_CONFIRMED]),
            'new_values' => json_encode([
                'state' => self::STATE_CONTAINED,
                'contained_by' => $containedBy,
                'containment_actions' => $containmentActions,
                'contained_at' => now()->toIso8601String(),
            ]),
            'user_id' => null,
            'user_type' => null,
            'ip_address' => request()?->ip(),
            'user_agent' => $containedBy,
            'tags' => json_encode(['security', 'key_compromise', 'contained']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::warning('Key compromise incident contained', [
            'incident_id' => $incidentId,
            'contained_by' => $containedBy,
        ]);

        return [
            'incident_id' => $incidentId,
            'state' => self::STATE_CONTAINED,
            'contained_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Mark incident as fully remediated.
     */
    public function markRemediated(
        string $incidentId,
        string $remediatedBy,
        string $remediationSummary,
        array $lessonsLearned = []
    ): array {
        DB::table('audit_logs')->insert([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'auditable_type' => 'KeyCompromise',
            'auditable_id' => $incidentId,
            'event' => 'compromise_remediated',
            'old_values' => json_encode(['state' => self::STATE_CONTAINED]),
            'new_values' => json_encode([
                'state' => self::STATE_REMEDIATED,
                'remediated_by' => $remediatedBy,
                'remediation_summary' => $remediationSummary,
                'lessons_learned' => $lessonsLearned,
                'remediated_at' => now()->toIso8601String(),
            ]),
            'user_id' => null,
            'user_type' => null,
            'ip_address' => request()?->ip(),
            'user_agent' => $remediatedBy,
            'tags' => json_encode(['security', 'key_compromise', 'remediated', 'closed']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::info('Key compromise incident fully remediated', [
            'incident_id' => $incidentId,
            'remediated_by' => $remediatedBy,
        ]);

        return [
            'incident_id' => $incidentId,
            'state' => self::STATE_REMEDIATED,
            'remediated_at' => now()->toIso8601String(),
            'closed' => true,
        ];
    }

    /**
     * Add certificate to global compromised list.
     */
    private function addToCompromisedList(string $certificateHash): void
    {
        $compromised = Cache::get(self::COMPROMISED_CERTS_KEY, []);
        if (!isset($compromised[$certificateHash])) {
            $compromised[$certificateHash] = [
                'added_at' => now()->toIso8601String(),
            ];
            Cache::forever(self::COMPROMISED_CERTS_KEY, $compromised);
        }
    }

    /**
     * Get all compromised certificates.
     */
    public function getCompromisedCertificates(): array
    {
        return Cache::get(self::COMPROMISED_CERTS_KEY, []);
    }
}
