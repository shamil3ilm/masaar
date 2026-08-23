<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Domains\Compliance\Fatoora\Enums\ErrorCode;
use App\Domains\Compliance\Fatoora\Exceptions\FatooraException;
use App\Domains\Compliance\Fatoora\Models\InvoiceSubmission;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * What has to be true before an invoice may be sent to ZATCA.
 *
 * Every check throws; passing means the submission may proceed. They live
 * together because they are one decision made in one place, and because a
 * check that is hard to reach is a check that stops being run.
 */
class SubmissionGuard
{
    /**
     * Every dependency is required, none optional.
     *
     * The container skips optional constructor parameters, so an optional
     * collaborator resolves to null and the check around it — duplicate
     * detection, VAT-period tracking, certificate health — never runs. The
     * check stays in the code and stops happening.
     */
    public function __construct(
        private readonly CertificateService $certificateService,
        private readonly TimestampValidator $timestampValidator,
        private readonly DuplicateDetector $duplicateDetector,
        private readonly VatPeriodTracker $vatPeriodTracker,
        private readonly CredentialStore $credentials,
        private readonly KillSwitch $killSwitch,
    ) {}

    /**
     * Throw unless this invoice may be submitted.
     *
     * @throws FatooraException
     */
    public function check(Invoice $invoice): void
    {
        $organization = $invoice->org;

        // 0. The emergency stop, which nothing consulted.
        //
        // KillSwitch exists to halt issuance and submission during an incident —
        // a ZATCA outage at month-end close, a bad go-live, a signing defect
        // caught in production. It offers isSubmissionBlocked(),
        // isClearanceBlocked(), isReportingBlocked(), isIssuanceBlocked() and
        // emergencyStop(), and the only call anywhere was in the offline queue's
        // batch loop. So an operator could throw the switch, watch replay stop,
        // and conclude submissions had halted while every live submission
        // continued to reach the authority.
        //
        // Per tenant as well as globally: the switch is scoped, and containing
        // one taxpayer's blast radius is the reason it is.
        $this->killSwitch->assertNotEnabled(KillSwitch::SWITCH_SUBMISSION, (string) $organization->id);
        $this->killSwitch->assertNotEnabled(
            $invoice->requiresClearance() ? KillSwitch::SWITCH_CLEARANCE : KillSwitch::SWITCH_REPORTING,
            (string) $organization->id
        );

        // 1. Check organization status
        if ($organization->isSuspended()) {
            throw new FatooraException(
                'Organization is suspended',
                ErrorCode::AUTH_ORGANIZATION_SUSPENDED
            );
        }

        // 2. Check certificate validity
        $this->checkCertificateHealth($organization);

        // 3. Check rate limits
        $this->checkRateLimits($organization);

        // 4. Check concurrent submissions
        $this->checkConcurrentSubmissions($organization);

        // 5. Check for duplicate submission
        $this->checkDuplicateSubmission($invoice);

        // 6. Verify invoice is in submittable state
        $this->verifyInvoiceState($invoice);

        // 7. Validate timestamp (±30 seconds drift enforcement)
        $this->validateInvoiceTimestamp($invoice);

        // 8. Validate VAT period for credit/debit notes
        $this->validateVatPeriod($invoice);
    }

    /**
     * Validate VAT period for credit/debit notes.
     *
     * Per ZATCA: Credit/debit notes issued after the original invoice's
     * VAT period has closed must be reported in the current period.
     */
    private function validateVatPeriod(Invoice $invoice): void
    {
        // Only applies to credit/debit notes
        $documentType = $invoice->document_type;
        if (! $documentType?->requiresBillingReference()) {
            return;
        }

        $validation = $this->vatPeriodTracker->validateAdjustmentPeriod($invoice);

        if (! $validation['valid']) {
            throw new FatooraException(
                $validation['warning'] ?? 'VAT period validation failed',
                ErrorCode::VAL_INVALID_FORMAT
            );
        }

        // Log cross-period warnings (non-blocking)
        if ($validation['warning']) {
            Log::warning('Cross-period VAT adjustment', [
                'invoice_id' => $invoice->id,
                'warning' => $validation['warning'],
                'suggested_period' => $validation['suggested_period'],
            ]);
        }
    }

    /**
     * Validate invoice timestamp against system time and ERP time.
     *
     * Enforces ±30 second drift tolerance as per compliance policy.
     *
     * @see docs/COMPLIANCE-POLICIES.md Section 7: Timestamp Authority
     */
    private function validateInvoiceTimestamp(Invoice $invoice): void
    {
        $invoiceTimestamp = $invoice->issue_date instanceof \DateTimeInterface
            ? $invoice->issue_date
            : new \DateTimeImmutable($invoice->issue_date);

        // The ERP's own timestamp would let this detect clock drift between the
        // calling system and the platform. It is not captured: there is no
        // erp_timestamp column and PipelineSubmitRequest accepts no such field,
        // only erp_reference_id. This read it from the invoice behind an isset,
        // so the drift comparison never ran and looked implemented. Passing null
        // says so. Wiring it up is a column and an API field, not a fix here.
        $validation = $this->timestampValidator->validateTimestamps(
            $invoiceTimestamp,
            null,
            null, // TSA timestamp added during signing
            null  // ZATCA received timestamp not yet known
        );

        // Log warnings but don't block
        if (! empty($validation['warnings'])) {
            Log::warning('Invoice timestamp validation warnings', [
                'invoice_id' => $invoice->id,
                'warnings' => $validation['warnings'],
                'drift_seconds' => $validation['drift_seconds'],
            ]);
        }

        // Block on errors (exceeds ±30 second tolerance)
        if (! $validation['valid']) {
            Log::error('Invoice timestamp validation failed', [
                'invoice_id' => $invoice->id,
                'errors' => $validation['errors'],
                'drift_seconds' => $validation['drift_seconds'],
            ]);

            throw new FatooraException(
                'Invoice timestamp validation failed: '.implode('; ', $validation['errors']),
                ErrorCode::VALIDATION_FAILED,
                [
                    'drift_seconds' => $validation['drift_seconds'],
                    'max_allowed' => TimestampValidator::MAX_DRIFT_SECONDS,
                ]
            );
        }
    }

    /**
     * Check certificate health with warnings for expiring certificates.
     */
    private function checkCertificateHealth(Organization $organization): void
    {
        // Read where the signing code reads. This used to be
        // $organization->zatca_certificate, which is neither a column nor an
        // accessor, so it was null for every organization and every submission
        // was refused here before reaching ZATCA.
        $credentials = $this->credentials->get(
            (string) $organization->id,
            null,
            CredentialStore::PCSID
        );

        $certificate = $credentials['pcsid'] ?? null;

        if (! $certificate) {
            throw new FatooraException(
                'ZATCA certificate not found',
                ErrorCode::CERT_NOT_FOUND
            );
        }

        // Check validity
        if (! $this->certificateService->isValid($certificate)) {
            throw new FatooraException(
                'ZATCA certificate is expired or invalid',
                ErrorCode::CERT_EXPIRED
            );
        }

        // Check expiry warning (30 days)
        $expiryDate = $this->certificateService->getExpiryDate($certificate);
        if ($expiryDate) {
            $expiryCarbon = Carbon::instance($expiryDate);
            $daysRemaining = $expiryCarbon->diffInDays(now());
            if ($daysRemaining <= 30) {
                Log::warning('ZATCA certificate expiring soon', [
                    'org_id' => $organization->id,
                    'expires_at' => $expiryCarbon->toIso8601String(),
                    'days_remaining' => $daysRemaining,
                ]);
            }
        }

        // Check revocation (non-blocking if check fails)
        try {
            $revocationStatus = $this->certificateService->checkRevocationStatus($certificate);
            if ($revocationStatus['revoked']) {
                throw new FatooraException(
                    'ZATCA certificate has been revoked',
                    ErrorCode::CERT_REVOKED
                );
            }
        } catch (FatooraException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::warning('Certificate revocation check failed', [
                'org_id' => $organization->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check rate limits.
     */
    private function checkRateLimits(Organization $organization): void
    {
        // Check per-minute rate limit
        $recentSubmissions = InvoiceSubmission::where('org_id', $organization->id)
            ->where('created_at', '>=', now()->subMinute())
            ->count();

        if ($recentSubmissions >= 60) {
            throw new FatooraException(
                'Rate limit exceeded (60/minute)',
                ErrorCode::RATE_LIMIT_EXCEEDED
            );
        }

        // Check daily limit
        $dailySubmissions = InvoiceSubmission::where('org_id', $organization->id)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        if ($dailySubmissions >= 10000) {
            throw new FatooraException(
                'Daily submission limit exceeded',
                ErrorCode::RATE_DAILY_LIMIT
            );
        }
    }

    /**
     * Check concurrent submission limits.
     */
    private function checkConcurrentSubmissions(Organization $organization): void
    {
        $inProgress = InvoiceSubmission::where('org_id', $organization->id)
            ->whereIn('state', ['queued', 'pending_submission', 'submitted'])
            ->count();

        if ($inProgress >= $this->getMaxConcurrentSubmissions()) {
            throw new FatooraException(
                'Maximum concurrent submissions reached',
                ErrorCode::RATE_CONCURRENT_LIMIT
            );
        }
    }

    /**
     * Check for duplicate submission.
     */
    private function checkDuplicateSubmission(Invoice $invoice): void
    {
        // Check if this exact invoice ID has already been submitted
        $existingSubmission = InvoiceSubmission::where('invoice_id', $invoice->id)
            ->whereIn('state', ['cleared', 'reported'])
            ->first();

        if ($existingSubmission) {
            $errorCode = $existingSubmission->state === 'cleared'
                ? ErrorCode::ZATCA_INVOICE_ALREADY_CLEARED
                : ErrorCode::ZATCA_INVOICE_ALREADY_REPORTED;

            throw new FatooraException($errorCode->getMessage(), $errorCode);
        }

        // Check for duplicate invoice numbers, UUIDs, or content hashes
        $duplicateCheck = $this->duplicateDetector->check(
            organizationId: $invoice->org_id,
            invoiceNumber: $invoice->invoice_number,
            uuid: $invoice->id,
            hash: $invoice->hash,
            fuzzyMatchData: [
                'buyer_vat' => $invoice->buyer_vat_number,
                'buyer_name' => $invoice->buyer_name,
                'total' => (float) $invoice->total,
                'issue_date' => $invoice->issue_date?->format('Y-m-d'),
            ]
        );

        if ($duplicateCheck['is_duplicate']) {
            $firstDuplicate = $duplicateCheck['duplicates'][0] ?? null;

            throw new FatooraException(
                'Duplicate invoice detected: '.($firstDuplicate['message'] ?? 'Unknown duplicate'),
                ErrorCode::VAL_INVALID_FORMAT,
                ['duplicates' => $duplicateCheck['duplicates']]
            );
        }
    }

    /**
     * Verify invoice is in a submittable state.
     */
    private function verifyInvoiceState(Invoice $invoice): void
    {
        // Check invoice has required data
        if (! $invoice->signed_xml) {
            throw new FatooraException(
                'Invoice must be signed before submission',
                ErrorCode::VAL_MISSING_REQUIRED_FIELD
            );
        }

        if (! $invoice->hash) {
            throw new FatooraException(
                'Invoice hash is missing',
                ErrorCode::ZATCA_INVALID_HASH
            );
        }

        if (! $invoice->qr_code) {
            throw new FatooraException(
                'QR code is missing',
                ErrorCode::ZATCA_INVALID_QR_CODE
            );
        }
    }

    /**
     * Maximum concurrent submissions per organization.
     */
    private function getMaxConcurrentSubmissions(): int
    {
        return (int) config('fatoora.rate_limits.max_concurrent', 10);
    }
}
