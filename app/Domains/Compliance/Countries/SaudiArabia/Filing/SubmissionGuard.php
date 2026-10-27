<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Filing;

use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\FatooraException;
use App\Domains\Compliance\Countries\SaudiArabia\Models\InvoiceSubmission;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\VatPeriodTracker;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CertificatePem;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CertificateService;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\FilingClearance;
use App\Domains\Platform\Services\KillSwitch;
use App\Support\Errors\ComplianceException;
use App\Support\Errors\ErrorCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
        private readonly FilingClearance $clearance,
        private readonly SubmissionRate $rate,
        private readonly FilingPause $pause,
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

        // 1. Check organization status: suspended, awaiting review or
        // rejected. Not its plan - an issued invoice is filed regardless.
        $this->clearance->assertMayFile($organization);

        // 2. Check certificate validity
        $this->checkCertificateHealth($organization);

        // ZATCA refused this device's credentials: nothing is sent from it
        // until they are renewed (FilingPause).
        $this->pause->assertFiling($invoice);

        // 3. Check rate limits: the device's, then the organization's.
        $this->rate->assertWithin($invoice);

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
     * @see docs/compliance/POLICIES.md Section 7: Timestamp Authority
     */
    private function validateInvoiceTimestamp(Invoice $invoice): void
    {
        // The moment it was issued, not issue_date. issue_date is the business
        // date on the Kingdom's clock, held as midnight UTC: between 21:00 and
        // 24:00 UTC the Kingdom is already on the next day, so that midnight
        // lay up to three hours ahead and every invoice issued then was
        // refused as "in the future" - found when CI happened to run at
        // 00:20 Riyadh time. issuedMoment() is the real instant.
        $invoiceTimestamp = $invoice->issuedMoment()->toDateTimeImmutable();

        // The ERP's own timestamp would let this detect clock drift between the
        // calling system and the platform. It is not captured: there is no
        // erp_timestamp column and PipelineSubmitRequest accepts no such field,
        // only erp_reference_id. This read it from the invoice behind an isset,
        // so the drift comparison never ran and looked implemented. Passing null
        // says so. Wiring it up is a column and an API field, not a fix here.
        $validation = $this->timestampValidator->validateTimestamps(
            $invoiceTimestamp,
            null,
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
        // Read from the credential store, which is where the signing code
        // reads. Organization has no zatca_certificate attribute; reading one
        // yields null, and a null here refuses the submission before it
        // reaches ZATCA.
        $credentials = $this->credentials->get(
            (string) $organization->id,
            null,
            CredentialStore::PCSID
        );

        $certificate = CertificatePem::from($credentials['pcsid'] ?? null);

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

        // Expiry warning, from 30 days out. now() to the expiry: Carbon 3's
        // diffIn* is signed, and the other way round every certificate read
        // as expired hundreds of days ago and was warned of on every invoice.
        // Once per certificate per threshold (30, 7, 1 days), not per invoice.
        $expiryDate = $this->certificateService->getExpiryDate($certificate);
        if ($expiryDate) {
            $expiryCarbon = Carbon::instance($expiryDate);
            $daysRemaining = now()->diffInDays($expiryCarbon);
            $threshold = collect([1, 7, 30])->first(fn (int $days): bool => $daysRemaining <= $days);
            if ($threshold !== null && Cache::add('zatca:certificate-expiry:'.hash('sha256', (string) $certificate).':'.$threshold, true, now()->addDays(31))) {
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
        } catch (ComplianceException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::warning('Certificate revocation check failed', [
                'org_id' => $organization->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check concurrent submission limits.
     */
    private function checkConcurrentSubmissions(Organization $organization): void
    {
        // Live ones only. A submission stuck in flight is not concurrent with
        // anything, and counted it filled the allowance for ever: enough of
        // them and the tenant could not file at all.
        $inProgress = InvoiceSubmission::where('org_id', $organization->id)
            ->live()
            ->count();

        if ($inProgress >= $this->getMaxConcurrentSubmissions()) {
            throw new FatooraException(
                'Maximum concurrent submissions reached',
                ErrorCode::RATE_CONCURRENT_LIMIT
            );
        }
    }

    /**
     * Throw if the invoice has a submission on its way to ZATCA or accepted.
     *
     * SubmissionTracker calls this a second time with the invoice row locked,
     * immediately before writing its own submission, because the look made
     * from check() comes before that lock and a concurrent request can pass it
     * as well.
     *
     * @throws FatooraException
     */
    public function assertNotSubmitted(Invoice $invoice): void
    {
        $existing = InvoiceSubmission::where('invoice_id', $invoice->id)
            ->whereIn('state', [...InvoiceSubmission::IN_FLIGHT_STATES, ...InvoiceSubmission::ACCEPTED_STATES])
            ->first();

        if ($existing === null) {
            return;
        }

        $errorCode = match (true) {
            in_array($existing->state, InvoiceSubmission::IN_FLIGHT_STATES, true) => ErrorCode::IDEM_PROCESSING_IN_PROGRESS,
            $existing->state === 'cleared', $existing->state === 'warning' && $existing->isClearance() => ErrorCode::ZATCA_INVOICE_ALREADY_CLEARED,
            default => ErrorCode::ZATCA_INVOICE_ALREADY_REPORTED,
        };

        throw new FatooraException($errorCode->getMessage(), $errorCode);
    }

    /**
     * Check for duplicate submission.
     */
    private function checkDuplicateSubmission(Invoice $invoice): void
    {
        $this->assertNotSubmitted($invoice);

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
