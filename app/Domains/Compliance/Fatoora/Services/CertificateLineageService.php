<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Domains\Compliance\Fatoora\Enums\ErrorCode;
use App\Domains\Compliance\Fatoora\Exceptions\FatooraException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Certificate Lineage Service - Track certificate lifecycle.
 *
 * CRITICAL: When a certificate is re-issued mid-month:
 * - Need to know which invoices were signed with which certificate
 * - Audit trail for certificate transitions
 * - Handle renewal vs revocation vs expiry
 *
 * Audit requirement: "Show me every invoice signed with certificate X"
 * must be answerable in <10 minutes.
 */
class CertificateLineageService
{
    /**
     * Certificate status values.
     */
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_SUPERSEDED = 'superseded';

    /**
     * Register a new certificate in the lineage.
     *
     * @param string $organizationId
     * @param string $certificateId Fingerprint/hash of certificate
     * @param string $certificateSerial Serial number from certificate
     * @param string $issuer Certificate issuer
     * @param \DateTimeInterface $validFrom
     * @param \DateTimeInterface $validTo
     * @return array The created lineage record
     */
    public function registerCertificate(
        string $organizationId,
        string $certificateId,
        string $certificateSerial,
        string $issuer,
        \DateTimeInterface $validFrom,
        \DateTimeInterface $validTo
    ): array {
        // Check if certificate already registered
        $existing = DB::table('certificate_lineage')
            ->where('certificate_id', $certificateId)
            ->first();

        if ($existing) {
            Log::debug('Certificate already registered', [
                'certificate_id' => $certificateId,
                'organization_id' => $organizationId,
            ]);
            return (array) $existing;
        }

        // Mark any existing active certificates as superseded
        $activeCert = $this->getActiveCertificate($organizationId);
        if ($activeCert) {
            $this->markSuperseded($activeCert['id'], $certificateId, 'New certificate registered');
        }

        $lineageId = Str::uuid()->toString();
        $record = [
            'id' => $lineageId,
            'organization_id' => $organizationId,
            'certificate_id' => $certificateId,
            'certificate_serial' => $certificateSerial,
            'issuer' => $issuer,
            'valid_from' => $validFrom->format('Y-m-d H:i:s'),
            'valid_to' => $validTo->format('Y-m-d H:i:s'),
            'first_icv' => null,
            'last_icv' => null,
            'status' => self::STATUS_ACTIVE,
            'superseded_by' => null,
            'transition_reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('certificate_lineage')->insert($record);

        Log::info('Certificate registered in lineage', [
            'lineage_id' => $lineageId,
            'certificate_id' => $certificateId,
            'organization_id' => $organizationId,
            'valid_from' => $validFrom->format('Y-m-d'),
            'valid_to' => $validTo->format('Y-m-d'),
        ]);

        return $record;
    }

    /**
     * Get the currently active certificate for an organization.
     *
     * Certificate Overlap Resolution Policy:
     * When multiple certificates are valid simultaneously, prefer the newest.
     * Uses activated_at as primary sort, then created_at as tiebreaker for
     * same-second edge cases, then id for absolute determinism.
     */
    public function getActiveCertificate(string $organizationId): ?array
    {
        $cert = DB::table('certificate_lineage')
            ->where('organization_id', $organizationId)
            ->where('status', self::STATUS_ACTIVE)
            ->orderByDesc('activated_at')   // Primary: newest activation wins
            ->orderByDesc('created_at')     // Secondary: same-second tiebreaker
            ->orderByDesc('id')             // Tertiary: absolute determinism
            ->first();

        return $cert ? (array) $cert : null;
    }

    /**
     * Record that an invoice was signed with a certificate.
     * Updates first_icv and last_icv tracking.
     */
    public function recordInvoiceSigned(
        string $organizationId,
        string $certificateId,
        int $icv
    ): void {
        $lineage = DB::table('certificate_lineage')
            ->where('organization_id', $organizationId)
            ->where('certificate_id', $certificateId)
            ->first();

        if (!$lineage) {
            Log::warning('Invoice signed with unregistered certificate', [
                'certificate_id' => $certificateId,
                'organization_id' => $organizationId,
                'icv' => $icv,
            ]);
            return;
        }

        $updates = ['last_icv' => $icv, 'updated_at' => now()];

        // Set first_icv only if not already set
        if ($lineage->first_icv === null) {
            $updates['first_icv'] = $icv;
        }

        DB::table('certificate_lineage')
            ->where('id', $lineage->id)
            ->update($updates);
    }

    /**
     * Mark a certificate as superseded by a new one.
     */
    public function markSuperseded(
        string $lineageId,
        string $supersededByCertId,
        ?string $reason = null
    ): void {
        DB::table('certificate_lineage')
            ->where('id', $lineageId)
            ->update([
                'status' => self::STATUS_SUPERSEDED,
                'superseded_by' => $supersededByCertId,
                'transition_reason' => $reason,
                'updated_at' => now(),
            ]);

        Log::info('Certificate marked as superseded', [
            'lineage_id' => $lineageId,
            'superseded_by' => $supersededByCertId,
            'reason' => $reason,
        ]);
    }

    /**
     * Mark a certificate as expired.
     */
    public function markExpired(string $certificateId): void
    {
        DB::table('certificate_lineage')
            ->where('certificate_id', $certificateId)
            ->update([
                'status' => self::STATUS_EXPIRED,
                'transition_reason' => 'Certificate validity period ended',
                'updated_at' => now(),
            ]);

        Log::warning('Certificate marked as expired', [
            'certificate_id' => $certificateId,
        ]);
    }

    /**
     * Mark a certificate as revoked.
     */
    public function markRevoked(string $certificateId, string $reason): void
    {
        DB::table('certificate_lineage')
            ->where('certificate_id', $certificateId)
            ->update([
                'status' => self::STATUS_REVOKED,
                'transition_reason' => $reason,
                'updated_at' => now(),
            ]);

        Log::critical('Certificate marked as REVOKED', [
            'certificate_id' => $certificateId,
            'reason' => $reason,
        ]);
    }

    /**
     * Get full certificate history for an organization.
     */
    public function getCertificateHistory(string $organizationId): array
    {
        return DB::table('certificate_lineage')
            ->where('organization_id', $organizationId)
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn($cert) => (array) $cert)
            ->toArray();
    }

    /**
     * Get all invoices signed with a specific certificate.
     * AUDIT REQUIREMENT: Must complete in <10 minutes.
     *
     * Uses the indexed hash_chain_history table for fast queries.
     */
    public function getInvoicesSignedWithCertificate(
        string $certificateId,
        ?string $organizationId = null,
        ?int $limit = 1000
    ): array {
        $query = DB::table('hash_chain_history')
            ->where('certificate_id', $certificateId);

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        return $query
            ->orderBy('icv', 'asc')
            ->limit($limit)
            ->get()
            ->map(fn($entry) => [
                'invoice_id' => $entry->invoice_id,
                'icv' => $entry->icv,
                'invoice_hash' => $entry->invoice_hash,
                'signed_at' => $entry->created_at,
            ])
            ->toArray();
    }

    /**
     * Get count of invoices signed with a certificate.
     */
    public function getInvoiceCountForCertificate(string $certificateId): int
    {
        return DB::table('hash_chain_history')
            ->where('certificate_id', $certificateId)
            ->count();
    }

    /**
     * Find certificates expiring within a given period.
     * Used for proactive renewal alerts.
     */
    public function getCertificatesExpiringSoon(int $days = 30): array
    {
        return DB::table('certificate_lineage')
            ->where('status', self::STATUS_ACTIVE)
            ->where('valid_to', '<=', now()->addDays($days))
            ->where('valid_to', '>', now())
            ->orderBy('valid_to', 'asc')
            ->get()
            ->map(fn($cert) => (array) $cert)
            ->toArray();
    }

    /**
     * Find already expired but still active certificates.
     */
    public function getExpiredActiveCertificates(): array
    {
        return DB::table('certificate_lineage')
            ->where('status', self::STATUS_ACTIVE)
            ->where('valid_to', '<', now())
            ->get()
            ->map(fn($cert) => (array) $cert)
            ->toArray();
    }

    /**
     * Validate that a certificate can be used for signing.
     *
     * @throws FatooraException if certificate is not valid
     */
    public function validateCertificateForSigning(string $organizationId, string $certificateId): void
    {
        $lineage = DB::table('certificate_lineage')
            ->where('organization_id', $organizationId)
            ->where('certificate_id', $certificateId)
            ->first();

        if (!$lineage) {
            throw new FatooraException(
                'Certificate not registered in lineage',
                ErrorCode::CERT_NOT_FOUND,
                ['certificate_id' => $certificateId]
            );
        }

        if ($lineage->status === self::STATUS_REVOKED) {
            throw new FatooraException(
                'Certificate has been revoked and cannot be used',
                ErrorCode::CERT_REVOKED,
                ['certificate_id' => $certificateId, 'reason' => $lineage->transition_reason]
            );
        }

        if ($lineage->status === self::STATUS_EXPIRED) {
            throw new FatooraException(
                'Certificate has expired',
                ErrorCode::CERT_EXPIRED,
                ['certificate_id' => $certificateId, 'valid_to' => $lineage->valid_to]
            );
        }

        if ($lineage->status === self::STATUS_SUPERSEDED) {
            throw new FatooraException(
                'Certificate has been superseded by a newer certificate',
                ErrorCode::CERT_INVALID,
                ['certificate_id' => $certificateId, 'superseded_by' => $lineage->superseded_by]
            );
        }

        // Check validity period
        $validTo = new \DateTimeImmutable($lineage->valid_to);
        if ($validTo < now()) {
            // Auto-mark as expired
            $this->markExpired($certificateId);

            throw new FatooraException(
                'Certificate validity period has ended',
                ErrorCode::CERT_EXPIRED,
                ['certificate_id' => $certificateId, 'valid_to' => $lineage->valid_to]
            );
        }
    }

    /**
     * Generate audit report for certificate usage.
     */
    public function generateAuditReport(string $organizationId): array
    {
        $certificates = $this->getCertificateHistory($organizationId);
        $report = [];

        foreach ($certificates as $cert) {
            $invoiceCount = $this->getInvoiceCountForCertificate($cert['certificate_id']);

            $report[] = [
                'certificate_id' => $cert['certificate_id'],
                'serial' => $cert['certificate_serial'],
                'issuer' => $cert['issuer'],
                'status' => $cert['status'],
                'valid_from' => $cert['valid_from'],
                'valid_to' => $cert['valid_to'],
                'first_icv' => $cert['first_icv'],
                'last_icv' => $cert['last_icv'],
                'invoice_count' => $invoiceCount,
                'transition_reason' => $cert['transition_reason'],
                'superseded_by' => $cert['superseded_by'],
            ];
        }

        return [
            'organization_id' => $organizationId,
            'generated_at' => now()->toIso8601String(),
            'total_certificates' => count($certificates),
            'certificates' => $report,
        ];
    }
}
