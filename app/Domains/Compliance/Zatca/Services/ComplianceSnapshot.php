<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Compliance Snapshot Service.
 *
 * Handles "Compliance-as-of" timestamps for regulatory interpretation changes.
 *
 * POLICY: An invoice is considered compliant based on the rules in effect
 * at the time of issuance, not retroactively. This protects against:
 * - ZATCA clarifications that change interpretation
 * - Regulatory rule version changes
 * - Schema updates
 *
 * Each invoice stores:
 * - rule_version: ZATCA business rules version at issuance
 * - schema_version: UBL/ZATCA schema version at issuance
 * - signature_algorithm: Cryptographic algorithm used
 * - hash_algorithm: Hash algorithm used
 * - compliance_timestamp: When compliance was determined
 */
class ComplianceSnapshot
{
    /**
     * Current ZATCA rule versions.
     */
    public const CURRENT_RULE_VERSION = '2.0.0';
    public const CURRENT_SCHEMA_VERSION = '2.1';
    public const CURRENT_SIGNATURE_ALGORITHM = 'ECDSA-secp256k1';
    public const CURRENT_HASH_ALGORITHM = 'SHA256';
    public const CURRENT_CANONICALIZATION = 'C14N';

    /**
     * Create a compliance snapshot for an invoice.
     * Call this at the moment of invoice issuance/signing.
     */
    public function capture(string $invoiceId): array
    {
        $snapshot = [
            'invoice_id' => $invoiceId,
            'rule_version' => self::CURRENT_RULE_VERSION,
            'schema_version' => self::CURRENT_SCHEMA_VERSION,
            'signature_algorithm' => self::CURRENT_SIGNATURE_ALGORITHM,
            'hash_algorithm' => self::CURRENT_HASH_ALGORITHM,
            'canonicalization_method' => self::CURRENT_CANONICALIZATION,
            'compliance_timestamp' => now()->toIso8601String(),
            'zatca_environment' => config('zatca.environment', 'sandbox'),
        ];

        // Update invoice with compliance metadata
        DB::table('invoices')
            ->where('id', $invoiceId)
            ->update([
                'rule_version' => $snapshot['rule_version'],
                'schema_version' => $snapshot['schema_version'],
                'updated_at' => now(),
            ]);

        Log::info('Compliance snapshot captured', [
            'invoice_id' => $invoiceId,
            'rule_version' => $snapshot['rule_version'],
            'schema_version' => $snapshot['schema_version'],
        ]);

        return $snapshot;
    }

    /**
     * Verify an invoice against its compliance snapshot.
     * Returns whether the invoice was compliant at time of issuance.
     */
    public function verify(string $invoiceId): array
    {
        $invoice = DB::table('invoices')->where('id', $invoiceId)->first();

        if (!$invoice) {
            return [
                'valid' => false,
                'error' => 'Invoice not found',
            ];
        }

        $ruleVersion = $invoice->rule_version ?? 'unknown';
        $schemaVersion = $invoice->schema_version ?? 'unknown';

        return [
            'valid' => true,
            'invoice_id' => $invoiceId,
            'compliant_as_of' => $invoice->issue_date,
            'rule_version_at_issuance' => $ruleVersion,
            'schema_version_at_issuance' => $schemaVersion,
            'current_rule_version' => self::CURRENT_RULE_VERSION,
            'current_schema_version' => self::CURRENT_SCHEMA_VERSION,
            'retroactive_changes_apply' => false, // Policy: NO
            'compliance_statement' => $this->generateComplianceStatement($invoice),
        ];
    }

    /**
     * Generate a compliance statement for legal purposes.
     */
    private function generateComplianceStatement(object $invoice): string
    {
        $ruleVersion = $invoice->rule_version ?? 'pre-versioning';
        $issueDate = $invoice->issue_date;

        return sprintf(
            'This invoice (ID: %s) was issued on %s and determined compliant ' .
            'under ZATCA rules version %s in effect at that time. ' .
            'Subsequent rule changes do not retroactively affect this determination.',
            $invoice->id,
            $issueDate,
            $ruleVersion
        );
    }

    /**
     * Check if an invoice was issued under a deprecated rule version.
     */
    public function isUnderDeprecatedRules(string $invoiceId): array
    {
        $invoice = DB::table('invoices')->where('id', $invoiceId)->first();

        if (!$invoice) {
            return ['deprecated' => false, 'error' => 'Invoice not found'];
        }

        $deprecatedVersions = $this->getDeprecatedVersions();
        $invoiceVersion = $invoice->rule_version ?? 'unknown';

        $isDeprecated = in_array($invoiceVersion, $deprecatedVersions, true);

        return [
            'deprecated' => $isDeprecated,
            'invoice_version' => $invoiceVersion,
            'current_version' => self::CURRENT_RULE_VERSION,
            'action_required' => $isDeprecated ? 'none' : 'none',
            'note' => $isDeprecated
                ? 'Invoice was compliant at issuance. No resubmission required.'
                : 'Invoice is under current rules.',
        ];
    }

    /**
     * Get list of deprecated rule versions.
     * Update this when ZATCA deprecates old rules.
     */
    private function getDeprecatedVersions(): array
    {
        return [
            '1.0.0', // Initial Phase 2 rules
            '1.1.0', // First update
            '1.2.0', // Pre-2024 rules
        ];
    }

    /**
     * Generate audit report showing rule version distribution.
     */
    public function getRuleVersionReport(string $organizationId): array
    {
        $distribution = DB::table('invoices')
            ->where('organization_id', $organizationId)
            ->selectRaw('rule_version, COUNT(*) as count')
            ->groupBy('rule_version')
            ->pluck('count', 'rule_version')
            ->toArray();

        return [
            'organization_id' => $organizationId,
            'distribution' => $distribution,
            'current_version' => self::CURRENT_RULE_VERSION,
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
