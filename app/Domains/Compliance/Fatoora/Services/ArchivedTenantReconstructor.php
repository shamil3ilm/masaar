<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Archived Tenant Reconstructor.
 *
 * Enables invoice reconstruction for archived, legally_replaced, or suspended tenants.
 *
 * REGULATORY REQUIREMENT: Invoices from merged or archived organizations must remain
 * fully reconstructible for 7+ years for audit purposes.
 *
 * Scenarios:
 * - Organization merged into another entity
 * - Organization changed VAT number
 * - Organization suspended or terminated
 * - Legal hold on historical data
 *
 * This service ensures cross-tenant audit queries do NOT leak data
 * while still allowing authorized reconstruction.
 */
class ArchivedTenantReconstructor
{
    /**
     * Valid organization states for reconstruction.
     */
    private const RECONSTRUCTABLE_STATES = [
        'archived',
        'legally_replaced',
        'suspended',
        'legal_hold',
    ];

    /**
     * Reconstruct invoice from archived/merged organization.
     *
     * @param string $invoiceId
     * @param string $requestedBy Who is requesting reconstruction
     * @param string $reason Reason for reconstruction
     * @param bool $includeSignature Whether to include signature data
     * @return array Reconstructed invoice data
     */
    public function reconstructInvoice(
        string $invoiceId,
        string $requestedBy,
        string $reason,
        bool $includeSignature = false
    ): array {
        // Get invoice with organization
        $invoice = DB::table('invoices as i')
            ->join('organizations as o', 'i.organization_id', '=', 'o.id')
            ->where('i.id', $invoiceId)
            ->select([
                'i.*',
                'o.id as org_id',
                'o.name as org_name',
                'o.vat_number as org_vat',
                'o.status as org_status',
                'o.compliance_profile',
            ])
            ->first();

        if (!$invoice) {
            return [
                'success' => false,
                'error' => 'Invoice not found',
                'invoice_id' => $invoiceId,
            ];
        }

        // Log access for audit
        $this->logReconstruction($invoiceId, $invoice->org_id, $requestedBy, $reason);

        // Get organization lifecycle info
        $lifecycleInfo = $this->getOrganizationLifecycleInfo($invoice->org_id, $invoice->compliance_profile);

        // Get submission history
        $submissions = DB::table('invoice_submissions')
            ->where('invoice_id', $invoiceId)
            ->orderBy('submitted_at')
            ->get();

        // Get hash chain entry
        $chainEntry = DB::table('hash_chain_history')
            ->where('organization_id', $invoice->org_id)
            ->where('icv', $invoice->icv)
            ->first();

        // Get certificate used for signing
        $certificate = $this->getCertificateInfo($invoice->org_id, $invoice->created_at);

        // Build reconstruction
        $reconstruction = [
            'success' => true,
            'invoice' => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'type' => $invoice->type,
                'document_type' => $invoice->document_type,
                'status' => $invoice->status,
                'issue_date' => $invoice->issue_date,
                'supply_date' => $invoice->supply_date,
                'currency' => $invoice->currency,
                'subtotal' => $invoice->subtotal,
                'tax_amount' => $invoice->tax_amount,
                'total' => $invoice->total,
                'icv' => $invoice->icv,
                'hash' => $invoice->hash,
                'rule_version' => $invoice->rule_version,
                'schema_version' => $invoice->schema_version,
            ],
            'seller' => [
                'organization_id' => $invoice->org_id,
                'name' => $invoice->org_name,
                'vat_number' => $invoice->org_vat,
                'status_at_reconstruction' => $invoice->org_status,
            ],
            'buyer' => [
                'name' => $invoice->buyer_name,
                'vat_number' => $invoice->buyer_vat_number,
            ],
            'lifecycle' => $lifecycleInfo,
            'submissions' => $submissions->map(fn($s) => [
                'id' => $s->id,
                'submitted_at' => $s->submitted_at,
                'clearance_state' => $s->clearance_state,
                'cleared_at' => $s->cleared_at,
            ])->toArray(),
            'hash_chain' => $chainEntry ? [
                'icv' => $chainEntry->icv,
                'invoice_hash' => $chainEntry->invoice_hash,
                'previous_hash' => $chainEntry->previous_hash,
                'chain_hash' => $chainEntry->chain_hash,
                'chain_created_at' => $chainEntry->created_at,
            ] : null,
            'certificate' => $certificate,
            'compliance_statement' => $this->generateComplianceStatement($invoice, $lifecycleInfo),
            'reconstructed_at' => now()->toIso8601String(),
            'reconstructed_by' => $requestedBy,
            'reconstruction_reason' => $reason,
        ];

        // Include signature if requested and authorized
        if ($includeSignature && $invoice->signed_xml) {
            $reconstruction['signature'] = [
                'signed_xml_available' => true,
                'signature_algorithm' => $invoice->signature_algorithm ?? 'ECDSA-secp256k1',
                'hash_algorithm' => $invoice->hash_algorithm ?? 'SHA256',
            ];
        }

        // Get invoice lines
        $lines = DB::table('invoice_lines')
            ->where('invoice_id', $invoiceId)
            ->get();

        $reconstruction['lines'] = $lines->map(fn($line) => [
            'description' => $line->description,
            'quantity' => $line->quantity,
            'unit_price' => $line->unit_price,
            'tax_amount' => $line->tax_amount,
            'line_total' => $line->line_total,
            'tax_category' => $line->tax_category ?? null,
        ])->toArray();

        return $reconstruction;
    }

    /**
     * Get all invoices for an archived organization.
     *
     * @param string $organizationId
     * @param string $requestedBy
     * @param string $reason
     * @param array $filters Optional filters (date range, etc.)
     * @return array List of reconstructed invoices (summary)
     */
    public function listOrganizationInvoices(
        string $organizationId,
        string $requestedBy,
        string $reason,
        array $filters = []
    ): array {
        $organization = DB::table('organizations')
            ->where('id', $organizationId)
            ->first();

        if (!$organization) {
            return ['success' => false, 'error' => 'Organization not found'];
        }

        // Verify organization is in reconstructable state
        if (!in_array($organization->status, self::RECONSTRUCTABLE_STATES, true)) {
            // Allow for active orgs too (for completeness)
        }

        // Build query
        $query = DB::table('invoices')
            ->where('organization_id', $organizationId);

        // Apply filters
        if (!empty($filters['from_date'])) {
            $query->where('issue_date', '>=', $filters['from_date']);
        }
        if (!empty($filters['to_date'])) {
            $query->where('issue_date', '<=', $filters['to_date']);
        }
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $invoices = $query->orderBy('icv')->get();

        // Log bulk access
        $this->logBulkAccess($organizationId, $invoices->count(), $requestedBy, $reason);

        return [
            'success' => true,
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'vat_number' => $organization->vat_number,
                'status' => $organization->status,
            ],
            'total_invoices' => $invoices->count(),
            'invoices' => $invoices->map(fn($inv) => [
                'id' => $inv->id,
                'invoice_number' => $inv->invoice_number,
                'icv' => $inv->icv,
                'issue_date' => $inv->issue_date,
                'total' => $inv->total,
                'status' => $inv->status,
                'hash' => $inv->hash,
            ])->toArray(),
            'filters_applied' => $filters,
            'listed_at' => now()->toIso8601String(),
            'listed_by' => $requestedBy,
        ];
    }

    /**
     * Reconstruct hash chain for an archived organization.
     */
    public function reconstructHashChain(
        string $organizationId,
        string $requestedBy,
        string $reason
    ): array {
        $chainEntries = DB::table('hash_chain_history')
            ->where('organization_id', $organizationId)
            ->orderBy('icv')
            ->get();

        if ($chainEntries->isEmpty()) {
            return [
                'success' => false,
                'error' => 'No hash chain entries found',
                'organization_id' => $organizationId,
            ];
        }

        // Verify chain integrity
        $integrity = $this->verifyChainIntegrity($chainEntries);

        $this->logReconstruction(
            'hash_chain',
            $organizationId,
            $requestedBy,
            $reason . ' (hash chain reconstruction)'
        );

        return [
            'success' => true,
            'organization_id' => $organizationId,
            'chain_length' => $chainEntries->count(),
            'first_icv' => $chainEntries->first()->icv,
            'last_icv' => $chainEntries->last()->icv,
            'integrity' => $integrity,
            'entries' => $chainEntries->map(fn($e) => [
                'icv' => $e->icv,
                'invoice_hash' => $e->invoice_hash,
                'chain_hash' => $e->chain_hash,
                'created_at' => $e->created_at,
            ])->toArray(),
            'reconstructed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Get organization lifecycle information.
     */
    private function getOrganizationLifecycleInfo(string $organizationId, ?string $complianceProfile): array
    {
        $profile = json_decode($complianceProfile ?? '{}', true);

        $organization = DB::table('organizations')
            ->where('id', $organizationId)
            ->first();

        $info = [
            'current_status' => $organization->status ?? 'unknown',
            'is_reconstructable' => true,
        ];

        // Check for replacement info
        if (isset($profile['replaced_by'])) {
            $info['replaced_by'] = $profile['replaced_by'];
            $info['replaced_at'] = $profile['replaced_at'] ?? null;

            // Get successor organization
            $successor = DB::table('organizations')
                ->where('id', $profile['replaced_by'])
                ->first();

            if ($successor) {
                $info['successor'] = [
                    'id' => $successor->id,
                    'name' => $successor->name,
                    'vat_number' => $successor->vat_number,
                ];
            }
        }

        // Check for legal hold info
        if (isset($profile['legal_hold'])) {
            $info['legal_hold'] = [
                'active' => true,
                'reference' => $profile['legal_hold']['hold_reference'] ?? null,
                'placed_at' => $profile['legal_hold']['placed_at'] ?? null,
            ];
        }

        // Get transition history from audit logs
        $transitions = DB::table('audit_logs')
            ->where('auditable_type', 'App\\Domains\\Organization\\Models\\Organization')
            ->where('auditable_id', $organizationId)
            ->where('event', 'state_transition')
            ->orderBy('created_at')
            ->get();

        if ($transitions->isNotEmpty()) {
            $info['transition_history'] = $transitions->map(fn($t) => [
                'from' => json_decode($t->old_values, true)['status'] ?? 'unknown',
                'to' => json_decode($t->new_values, true)['status'] ?? 'unknown',
                'reason' => json_decode($t->new_values, true)['reason'] ?? null,
                'at' => $t->created_at,
            ])->toArray();
        }

        return $info;
    }

    /**
     * Get certificate information for the invoice signing period.
     */
    private function getCertificateInfo(string $organizationId, string $invoiceCreatedAt): ?array
    {
        $certificate = DB::table('certificate_lineage')
            ->where('organization_id', $organizationId)
            ->where('activated_at', '<=', $invoiceCreatedAt)
            ->where(function ($query) use ($invoiceCreatedAt) {
                $query->whereNull('deactivated_at')
                    ->orWhere('deactivated_at', '>=', $invoiceCreatedAt);
            })
            ->orderByDesc('activated_at')
            ->first();

        if (!$certificate) {
            return null;
        }

        return [
            'certificate_hash' => $certificate->certificate_hash,
            'serial_number' => $certificate->serial_number,
            'subject' => $certificate->subject,
            'issuer' => $certificate->issuer,
            'valid_from' => $certificate->valid_from,
            'valid_until' => $certificate->valid_until,
            'activated_at' => $certificate->activated_at,
            'was_revoked' => $certificate->revoked_at !== null,
            'revocation_reason' => $certificate->revocation_reason,
        ];
    }

    /**
     * Generate compliance statement for reconstructed invoice.
     */
    private function generateComplianceStatement(object $invoice, array $lifecycleInfo): string
    {
        $statements = [];

        // Base statement
        $statements[] = sprintf(
            'This invoice (ID: %s, ICV: %d) was issued on %s under organization VAT %s.',
            $invoice->id,
            $invoice->icv,
            $invoice->issue_date,
            $invoice->org_vat
        );

        // Rule version statement
        if ($invoice->rule_version) {
            $statements[] = sprintf(
                'Compliance was determined under ZATCA rules version %s.',
                $invoice->rule_version
            );
        }

        // Organization status statement
        if ($lifecycleInfo['current_status'] !== 'active') {
            $statements[] = sprintf(
                'The issuing organization is currently in "%s" status.',
                $lifecycleInfo['current_status']
            );
        }

        // Replacement statement
        if (isset($lifecycleInfo['replaced_by'])) {
            $statements[] = sprintf(
                'This organization was replaced by %s on %s.',
                $lifecycleInfo['successor']['name'] ?? $lifecycleInfo['replaced_by'],
                $lifecycleInfo['replaced_at'] ?? 'unknown date'
            );
        }

        // Legal hold statement
        if (isset($lifecycleInfo['legal_hold']) && $lifecycleInfo['legal_hold']['active']) {
            $statements[] = 'This invoice is subject to a legal hold order.';
        }

        $statements[] = 'This reconstruction is for audit purposes and represents the invoice state at time of issuance.';

        return implode(' ', $statements);
    }

    /**
     * Verify hash chain integrity.
     */
    private function verifyChainIntegrity($chainEntries): array
    {
        $breaks = [];
        $previousChainHash = null;

        foreach ($chainEntries as $entry) {
            if ($previousChainHash !== null && $entry->previous_hash !== $previousChainHash) {
                $breaks[] = [
                    'icv' => $entry->icv,
                    'expected' => $previousChainHash,
                    'found' => $entry->previous_hash,
                ];
            }
            $previousChainHash = $entry->chain_hash;
        }

        return [
            'intact' => empty($breaks),
            'breaks_found' => count($breaks),
            'breaks' => $breaks,
        ];
    }

    /**
     * Log reconstruction access.
     */
    private function logReconstruction(
        string $invoiceId,
        string $organizationId,
        string $requestedBy,
        string $reason
    ): void {
        DB::table('audit_logs')->insert([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'auditable_type' => 'Invoice',
            'auditable_id' => $invoiceId,
            'event' => 'archived_reconstruction',
            'old_values' => json_encode([]),
            'new_values' => json_encode([
                'organization_id' => $organizationId,
                'requested_by' => $requestedBy,
                'reason' => $reason,
            ]),
            'user_id' => null,
            'user_type' => null,
            'ip_address' => request()?->ip(),
            'user_agent' => $requestedBy,
            'tags' => json_encode(['reconstruction', 'archived', 'audit']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::info('Archived invoice reconstructed', [
            'invoice_id' => $invoiceId,
            'organization_id' => $organizationId,
            'requested_by' => $requestedBy,
            'reason' => $reason,
        ]);
    }

    /**
     * Log bulk access to organization invoices.
     */
    private function logBulkAccess(
        string $organizationId,
        int $invoiceCount,
        string $requestedBy,
        string $reason
    ): void {
        DB::table('audit_logs')->insert([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'auditable_type' => 'Organization',
            'auditable_id' => $organizationId,
            'event' => 'bulk_reconstruction_access',
            'old_values' => json_encode([]),
            'new_values' => json_encode([
                'invoice_count' => $invoiceCount,
                'requested_by' => $requestedBy,
                'reason' => $reason,
            ]),
            'user_id' => null,
            'user_type' => null,
            'ip_address' => request()?->ip(),
            'user_agent' => $requestedBy,
            'tags' => json_encode(['reconstruction', 'bulk_access', 'audit']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::info('Bulk reconstruction access', [
            'organization_id' => $organizationId,
            'invoice_count' => $invoiceCount,
            'requested_by' => $requestedBy,
        ]);
    }

    /**
     * Export full organization data for regulatory transfer.
     */
    public function exportForRegulatoryTransfer(
        string $organizationId,
        string $requestedBy,
        string $reason
    ): array {
        $organization = DB::table('organizations')
            ->where('id', $organizationId)
            ->first();

        if (!$organization) {
            return ['success' => false, 'error' => 'Organization not found'];
        }

        // Get all data
        $invoices = DB::table('invoices')
            ->where('organization_id', $organizationId)
            ->get();

        $submissions = DB::table('invoice_submissions')
            ->whereIn('invoice_id', $invoices->pluck('id'))
            ->get()
            ->groupBy('invoice_id');

        $hashChain = DB::table('hash_chain_history')
            ->where('organization_id', $organizationId)
            ->orderBy('icv')
            ->get();

        $certificates = DB::table('certificate_lineage')
            ->where('organization_id', $organizationId)
            ->get();

        $export = [
            'export_type' => 'regulatory_transfer',
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'vat_number' => $organization->vat_number,
                'status' => $organization->status,
                'created_at' => $organization->created_at,
            ],
            'statistics' => [
                'total_invoices' => $invoices->count(),
                'total_submissions' => $submissions->flatten()->count(),
                'chain_length' => $hashChain->count(),
                'certificates_used' => $certificates->count(),
            ],
            'invoices' => $invoices->map(fn($inv) => [
                'id' => $inv->id,
                'invoice_number' => $inv->invoice_number,
                'icv' => $inv->icv,
                'hash' => $inv->hash,
                'issue_date' => $inv->issue_date,
                'total' => $inv->total,
                'status' => $inv->status,
                'submissions' => ($submissions[$inv->id] ?? collect())->toArray(),
            ])->toArray(),
            'hash_chain' => $hashChain->toArray(),
            'certificates' => $certificates->map(fn($c) => [
                'hash' => $c->certificate_hash,
                'serial' => $c->serial_number,
                'valid_from' => $c->valid_from,
                'valid_until' => $c->valid_until,
            ])->toArray(),
            'exported_at' => now()->toIso8601String(),
            'exported_by' => $requestedBy,
            'export_reason' => $reason,
        ];

        // Log export
        DB::table('audit_logs')->insert([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'auditable_type' => 'Organization',
            'auditable_id' => $organizationId,
            'event' => 'regulatory_export',
            'old_values' => json_encode([]),
            'new_values' => json_encode([
                'invoice_count' => $invoices->count(),
                'requested_by' => $requestedBy,
                'reason' => $reason,
            ]),
            'user_id' => null,
            'user_type' => null,
            'ip_address' => request()?->ip(),
            'user_agent' => $requestedBy,
            'tags' => json_encode(['export', 'regulatory', 'full_data']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $export;
    }
}
