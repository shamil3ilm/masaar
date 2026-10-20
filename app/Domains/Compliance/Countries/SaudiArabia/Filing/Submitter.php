<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Filing;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Compliance\Countries\SaudiArabia\Document\DocumentBuilder;
use App\Domains\Compliance\Countries\SaudiArabia\DTOs\FatooraResponse;
use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\FatooraException;
use App\Domains\Compliance\Countries\SaudiArabia\Issuing\IssuingStep;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraEnvironment;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CertificateService;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\SigningCredentials;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\IssuingLifecycle;
use App\Domains\Licensing\Enums\LicenseEnvironment;
use App\Domains\Organization\Models\Organization;
use App\Support\Errors\ComplianceException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ZATCA submission service.
 *
 * Orchestrates the full invoice submission workflow:
 * - Generate compliance data
 * - Choose clearance vs reporting
 * - Submit to ZATCA
 * - Update invoice state
 *
 * This is the single entry point for ZATCA submissions.
 */
class Submitter
{
    /**
     * Every dependency is required, none optional: the container skips
     * optional constructor parameters and would leave them null.
     */
    public function __construct(
        private readonly DocumentBuilder $compliance,
        private readonly FatooraClient $client,
        private readonly AuditService $audit,
        private readonly CertificateService $certificateService,
        private readonly CredentialStore $credentials,
        private readonly InvoiceVerdict $verdict,
        private readonly SigningCredentials $signing,
        private readonly IssuingLifecycle $lifecycle,
        private readonly IssuingStep $zatca,
    ) {}

    /**
     * Generate compliance data and issue invoice.
     *
     * The lifecycle every country shares - lock, number, moment of issue,
     * status - is IssuingLifecycle's; ZATCA's checks, counter, seal and chain
     * entry are IssuingStep's.
     *
     * @param  bool  $reissue  Allow a document that is no longer a draft to be
     *                         signed again. Only for a caller completing an
     *                         issuance that did not finish.
     * @return array{hash: string, qr_code: string}
     *
     * @throws FatooraException If the invoice is already issued and $reissue is false
     */
    public function generate(Invoice $invoice, Organization $organization, bool $reissue = false): array
    {
        return $this->lifecycle->issue($invoice, $organization, $this->zatca, $reissue);
    }

    /**
     * Validate invoice with ZATCA (without submission).
     */
    public function validate(Invoice $invoice, Organization $organization): FatooraResponse
    {
        $previousHash = $invoice->previous_invoice_hash;
        $credentials = $this->signing->forInvoice($invoice);

        $complianceData = $this->compliance->generateComplianceData(
            invoice: $invoice,
            organization: $organization,
            previousInvoiceHash: $previousHash,
            privateKey: $credentials['privateKey'] ?? null,
            certificate: $credentials['certificate'] ?? null,
        );

        return $this->client->checkCompliance(
            invoiceXml: $complianceData['xml'],
            invoiceHash: $complianceData['hash'],
            uuid: $invoice->id,
            // ZATCA's compliance endpoint takes the compliance CSID, not the
            // production one.
            device: $this->credentials->device((string) $invoice->org_id, SigningCredentials::keyOf($invoice), CredentialStore::CCSID),
        );
    }

    /**
     * Submit invoice to ZATCA (clearance or reporting).
     *
     * COMPLIANCE: Validates license environment matches ZATCA environment.
     * Sandbox licenses cannot submit to production ZATCA.
     *
     * Supports both branch-level and organization-level credentials.
     * If invoice has a branch_id, uses branch credentials; otherwise falls back to org credentials.
     *
     * @throws FatooraException If organization not onboarded or credentials missing
     */
    public function submit(Invoice $invoice, Organization $organization): FatooraResponse
    {
        // CRITICAL: Validate organization has completed ZATCA onboarding
        $this->validateOnboarding($organization);

        // Validate environment before submission
        $this->validateEnvironment($organization);

        // Issuance is what allocates the counter and fixes the predecessor,
        // and generate() is the only place that happens. A document submitted
        // without having been issued used to reach the authority carrying
        // ICV 0 and the genesis PIH, because this method builds its own
        // document and nothing here had assigned either.
        //
        // Calling it here rather than throwing keeps the direct submit path
        // working for callers that never asked for a separate issuance step.
        //
        // The opt-in is passed because this reaches generate() only when the
        // counter or the hash is missing, so the document was never fully
        // issued - a half-finished issuance is being completed, not repeated,
        // and there is no hash for a second one to overwrite.
        if ($invoice->icv === null || $invoice->hash === null) {
            $this->generate($invoice, $organization, reissue: true);
            $invoice->refresh();
        }

        // The till it was issued on files it, with that till's credentials:
        // head office's under the organization's id.
        $unit = $this->signing->unitOf($invoice);
        $credentials = $this->signing->forInvoice($invoice, required: true);

        // Validate certificate is valid and not revoked before submission
        $this->validateCertificate($credentials['certificate']);

        // A till other than head office files once its own onboarding is
        // done and its certificate is current, and while its branch is
        // active. A retiring till still files what it signed; a retired or
        // lost one has nothing left to file.
        $branch = $invoice->branch_id !== null ? $invoice->branch : null;

        if (! $unit->isHeadOffice() && (! $unit->isReady() || ($branch !== null && ! $branch->is_active))) {
            throw FatooraException::notOnboarded(
                'Till is not ready for invoice submission. '.
                'Status: '.$unit->onboarding_status.', '.$unit->status,
                [
                    'device_id' => $unit->id,
                    'branch_id' => $unit->branch_id,
                    'onboarding_status' => $unit->onboarding_status,
                    'status' => $unit->status,
                ]
            );
        }

        // A branch issuing on head office's certificate is still a location
        // its organization can stop: suspending it, or its first till's
        // onboarding not being done, keeps it from filing.
        if ($unit->isHeadOffice() && $branch !== null && ! $branch->isFatooraReady()) {
            throw FatooraException::notOnboarded(
                'Branch is not ready for invoice submission. '.
                'Status: '.$branch->onboarding_status,
                [
                    'branch_id' => $branch->id,
                    'branch_name' => $branch->name,
                    'onboarding_status' => $branch->onboarding_status,
                ]
            );
        }

        // The document that was issued, not a new one.
        //
        // This used to call generateComplianceData() a second time and send
        // that. Signing again produces a different XAdES SigningTime, so the
        // bytes ZATCA saw were never the bytes on the invoice — and any input
        // that had moved since issuance made them differ in ways that matter.
        // The archive is the evidence of what was issued; if it does not match
        // what the authority holds, it is evidence of something else.
        //
        // Issuance above guarantees both are present.
        $invoiceXml = (string) $invoice->signed_xml;
        $invoiceHash = (string) $invoice->hash;
        $device = $this->credentials->forInvoice((string) $invoice->org_id, $invoice->device_id, $invoice->branch_id, (string) $invoice->id, $invoice->signed_xml);

        // Choose clearance (B2B) or reporting (B2C) based on invoice type
        if ($invoice->requiresClearance()) {
            // B2B: Submit for clearance (no deadline)
            $response = $this->client->clearInvoice(
                invoiceXml: $invoiceXml,
                invoiceHash: $invoiceHash,
                uuid: $invoice->id,
                device: $device,
            );
        } else {
            // B2C: report. ZATCA gives it 24 hours, and a late report is still
            // owed - the overdue sweep and the deadline screen say it is late;
            // refusing to send it would only make sure it never arrives.
            $response = $this->client->reportInvoice(
                invoiceXml: $invoiceXml,
                invoiceHash: $invoiceHash,
                uuid: $invoice->id,
                device: $device,
            );
        }

        // The invoice's status, its branch's count and the audit entry describe
        // one answer, so they commit together or not at all.
        DB::transaction(function () use ($invoice, $response): void {
            $this->verdict->record($invoice, $response);

            $this->audit->logZatcaSubmission($invoice, $response->success, [
                'clearance_status' => $response->clearanceStatus,
                'reporting_status' => $response->reportingStatus,
                'errors' => $response->errorMessages,
            ]);
        });

        return $response;
    }

    /**
     * Validate certificate is valid and not revoked.
     *
     * COMPLIANCE: Checks certificate expiry and revocation status before submission.
     * Prevents submission with expired or revoked certificates.
     *
     * @throws FatooraException If certificate is invalid, expired, or revoked
     */
    private function validateCertificate(?string $certificate): void
    {
        if (empty($certificate)) {
            return; // Already handled by SigningCredentials::for
        }

        // Check if certificate validation is enabled
        if (! config('fatoora.features.certificate_revocation_check', true)) {
            return;
        }

        try {
            $validation = $this->certificateService->validateForSubmission($certificate);

            if (! $validation['valid']) {
                $errors = $validation['errors'] ?? [];
                throw FatooraException::certificate(
                    'Certificate validation failed: '.implode('; ', $errors),
                    context: [
                        'errors' => $errors,
                        'warnings' => $validation['warnings'] ?? [],
                        'days_until_expiry' => $validation['days_until_expiry'] ?? null,
                    ]
                );
            }

            // Log warning if certificate is expiring soon
            $daysUntilExpiry = $validation['days_until_expiry'] ?? null;
            if ($daysUntilExpiry !== null && $daysUntilExpiry <= 30) {
                Log::warning('Certificate expiring soon', [
                    'days_until_expiry' => $daysUntilExpiry,
                    'warnings' => $validation['warnings'] ?? [],
                ]);
            }
        } catch (ComplianceException $e) {
            throw $e;
        } catch (\Exception $e) {
            // Log but don't block submission if validation fails unexpectedly
            Log::warning('Certificate validation failed unexpectedly', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Validate organization has completed ZATCA onboarding.
     *
     * COMPLIANCE: Organizations must complete the full onboarding process
     * (CSR generation, compliance check, PCSID acquisition) before submitting invoices.
     *
     * @throws FatooraException If organization not onboarded
     */
    private function validateOnboarding(Organization $organization): void
    {
        if (! $organization->zatca_onboarded) {
            throw FatooraException::notOnboarded(
                'Organization has not completed ZATCA onboarding. '.
                'Complete the 3-step onboarding process before submitting invoices: '.
                '1) Generate CSR and get CCSID, 2) Pass compliance checks, 3) Get PCSID.',
                [
                    'org_id' => $organization->id,
                    'zatca_onboarded' => false,
                ]
            );
        }
    }

    /**
     * Validate license environment matches ZATCA environment.
     *
     * COMPLIANCE: Prevents sandbox API keys from submitting to production ZATCA.
     * This is a critical safety check to avoid test data in production.
     *
     * @throws FatooraException If environment mismatch detected
     */
    private function validateEnvironment(Organization $organization): void
    {
        // The organization's own (FatooraEnvironment), where it files.
        $zatcaEnvironment = FatooraEnvironment::of($organization);

        // If ZATCA is configured for production, verify license allows production
        if ($zatcaEnvironment === 'production') {
            $license = request()->attributes->get('license');

            if ($license !== null) {
                $licenseEnv = $license->environment;

                // Sandbox licenses cannot submit to production ZATCA
                if ($licenseEnv === LicenseEnvironment::Sandbox) {
                    throw FatooraException::environmentMismatch(
                        'Sandbox API keys cannot submit invoices to production ZATCA. '.
                        'Please use a production API key (cp_live_*) for real invoice submissions.',
                        [
                            'license_environment' => $licenseEnv->value,
                            'zatca_environment' => $zatcaEnvironment,
                        ]
                    );
                }
            }
        }

        // If license is production but ZATCA is sandbox, log warning but allow
        // (useful for testing production keys against sandbox)
        $license = request()->attributes->get('license');
        if ($license !== null && $license->environment === LicenseEnvironment::Production) {
            if ($zatcaEnvironment === 'sandbox') {
                Log::info('Production license submitting to sandbox ZATCA', [
                    'license_id' => $license->id,
                    'zatca_environment' => $zatcaEnvironment,
                ]);
            }
        }
    }
}
