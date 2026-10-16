<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Compliance\Fatoora\Client\FatooraClient;
use App\Domains\Compliance\Fatoora\DTOs\FatooraResponse;
use App\Domains\Compliance\Fatoora\Exceptions\FatooraException;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\IssuingLifecycle;
use App\Domains\Licensing\Enums\LicenseEnvironment;
use App\Domains\Organization\Models\Organization;
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
        private readonly ZatcaJurisdiction $zatca,
    ) {}

    /**
     * Generate compliance data and issue invoice.
     *
     * The lifecycle every country shares - lock, number, moment of issue,
     * status - is IssuingLifecycle's; ZATCA's checks, counter, seal and chain
     * entry are ZatcaJurisdiction's.
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
        $credentials = $this->signing->for($organization->id, $this->signing->branchOf($invoice));

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
            device: $this->credentials->device((string) $invoice->org_id, $invoice->branch_id, CredentialStore::CCSID),
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
        $this->validateEnvironment();

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

        // A branch signs with its own credentials; an invoice that names no
        // branch signs with the organization's. invoices.branch_id is nullable
        // rather than absent, so isset() is asking whether this document came
        // from a particular EGS unit, not whether the column exists.
        $branch = $this->signing->branchOf($invoice);
        $credentials = $this->signing->for($organization->id, $branch, required: true);

        // Validate certificate is valid and not revoked before submission
        $this->validateCertificate($credentials['certificate']);

        // Validate branch is active if invoice has branch
        if ($branch && ! $branch->isFatooraReady()) {
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
        $device = $this->credentials->forInvoice((string) $invoice->org_id, $invoice->device_id, $invoice->branch_id);

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
        } catch (FatooraException $e) {
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
    private function validateEnvironment(): void
    {
        $zatcaEnvironment = $this->client->getEnvironment();

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
