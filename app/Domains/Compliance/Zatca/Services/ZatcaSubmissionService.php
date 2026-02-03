<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\Services;

use App\Audits\AuditService;
use App\Domains\Compliance\Zatca\Client\ZatcaClient;
use App\Domains\Compliance\Zatca\DTOs\ZatcaResponse;
use App\Domains\Compliance\Zatca\Exceptions\ZatcaException;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Enums\LicenseEnvironment;
use App\Domains\Organization\Models\Organization;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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
class ZatcaSubmissionService
{
    public function __construct(
        private readonly ZatcaComplianceService $compliance,
        private readonly ZatcaClient $client,
        private readonly AuditService $audit,
        private readonly ?CertificateService $certificateService = null,
    ) {}

    /**
     * Generate compliance data and issue invoice.
     *
     * @return array{hash: string, qr_code: string}
     */
    public function generate(Invoice $invoice, Organization $organization): array
    {
        $previousHash = $this->getPreviousInvoiceHash($invoice);

        // Get signing credentials if available
        $credentials = $this->getSigningCredentials($organization->id);

        $complianceData = $this->compliance->generateComplianceData(
            invoice: $invoice,
            organization: $organization,
            previousInvoiceHash: $previousHash,
            privateKey: $credentials['privateKey'] ?? null,
            certificate: $credentials['certificate'] ?? null,
        );

        // Update invoice with compliance data
        $invoice->update([
            'hash' => $complianceData['hash'],
            'qr_code' => $complianceData['qr_code'],
            'status' => InvoiceStatus::Issued,
        ]);

        return [
            'hash' => $complianceData['hash'],
            'qr_code' => $complianceData['qr_code'],
        ];
    }

    /**
     * Validate invoice with ZATCA (without submission).
     */
    public function validate(Invoice $invoice, Organization $organization): ZatcaResponse
    {
        $credentials = $this->getSigningCredentials($organization->id);

        $complianceData = $this->compliance->generateComplianceData(
            invoice: $invoice,
            organization: $organization,
            privateKey: $credentials['privateKey'] ?? null,
            certificate: $credentials['certificate'] ?? null,
        );

        return $this->client->checkCompliance(
            invoiceXml: $complianceData['xml'],
            invoiceHash: $complianceData['hash'],
            uuid: $invoice->id,
        );
    }

    /**
     * Submit invoice to ZATCA (clearance or reporting).
     *
     * COMPLIANCE: Validates license environment matches ZATCA environment.
     * Sandbox licenses cannot submit to production ZATCA.
     *
     * @throws ZatcaException If organization not onboarded or credentials missing
     */
    public function submit(Invoice $invoice, Organization $organization): ZatcaResponse
    {
        // CRITICAL: Validate organization has completed ZATCA onboarding
        $this->validateOnboarding($organization);

        // Validate environment before submission
        $this->validateEnvironment();

        $credentials = $this->getSigningCredentials($organization->id, required: true);

        // Validate certificate is valid and not revoked before submission
        $this->validateCertificate($credentials['certificate']);

        $complianceData = $this->compliance->generateComplianceData(
            invoice: $invoice,
            organization: $organization,
            privateKey: $credentials['privateKey'] ?? null,
            certificate: $credentials['certificate'] ?? null,
        );

        // Choose clearance (B2B) or reporting (B2C) based on invoice type
        $response = $invoice->requiresClearance()
            ? $this->client->clearInvoice(
                invoiceXml: $complianceData['xml'],
                invoiceHash: $complianceData['hash'],
                uuid: $invoice->id,
            )
            : $this->client->reportInvoice(
                invoiceXml: $complianceData['xml'],
                invoiceHash: $complianceData['hash'],
                uuid: $invoice->id,
            );

        // Update invoice status based on response
        $this->updateInvoiceStatus($invoice, $response);

        // Audit log the ZATCA submission
        $this->audit->logZatcaSubmission($invoice, $response->success, [
            'clearance_status' => $response->clearanceStatus,
            'reporting_status' => $response->reportingStatus,
            'errors' => $response->errorMessages,
        ]);

        return $response;
    }

    /**
     * Validate certificate is valid and not revoked.
     *
     * COMPLIANCE: Checks certificate expiry and revocation status before submission.
     * Prevents submission with expired or revoked certificates.
     *
     * @throws ZatcaException If certificate is invalid, expired, or revoked
     */
    private function validateCertificate(?string $certificate): void
    {
        if (empty($certificate)) {
            return; // Already handled by getSigningCredentials
        }

        // Only validate if CertificateService is available
        if ($this->certificateService === null) {
            return;
        }

        // Check if certificate validation is enabled
        if (!config('zatca.features.certificate_revocation_check', true)) {
            return;
        }

        try {
            $validation = $this->certificateService->validateForSubmission($certificate);

            if (!$validation['valid']) {
                $errors = $validation['errors'] ?? [];
                throw ZatcaException::certificate(
                    'Certificate validation failed: ' . implode('; ', $errors),
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
        } catch (ZatcaException $e) {
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
     * @throws ZatcaException If organization not onboarded
     */
    private function validateOnboarding(Organization $organization): void
    {
        if (!$organization->zatca_onboarded) {
            throw ZatcaException::notOnboarded(
                'Organization has not completed ZATCA onboarding. ' .
                'Complete the 3-step onboarding process before submitting invoices: ' .
                '1) Generate CSR and get CCSID, 2) Pass compliance checks, 3) Get PCSID.',
                [
                    'organization_id' => $organization->id,
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
     * @throws ZatcaException If environment mismatch detected
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
                    throw ZatcaException::environmentMismatch(
                        'Sandbox API keys cannot submit invoices to production ZATCA. ' .
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
                \Illuminate\Support\Facades\Log::info('Production license submitting to sandbox ZATCA', [
                    'license_id' => $license->id,
                    'zatca_environment' => $zatcaEnvironment,
                ]);
            }
        }
    }

    /**
     * Update invoice status after ZATCA response.
     */
    private function updateInvoiceStatus(Invoice $invoice, ZatcaResponse $response): void
    {
        $invoice->update([
            'status' => $response->success ? InvoiceStatus::Accepted : InvoiceStatus::Rejected,
            'zatca_response' => [
                'clearance_status' => $response->clearanceStatus,
                'reporting_status' => $response->reportingStatus,
                'validation_status' => $response->validationStatus,
                'warnings' => $response->warningMessages,
                'errors' => $response->errorMessages,
            ],
        ]);
    }

    /**
     * Get hash of previous invoice for chaining.
     */
    private function getPreviousInvoiceHash(Invoice $invoice): ?string
    {
        $previous = Invoice::where('organization_id', $invoice->organization_id)
            ->where('created_at', '<', $invoice->created_at)
            ->whereNotNull('hash')
            ->orderBy('created_at', 'desc')
            ->first();

        return $previous?->hash;
    }

    /**
     * Get signing credentials for organization.
     *
     * @param string $organizationId The organization ID
     * @param bool $required If true, throws exception when credentials are missing
     * @return array{privateKey: ?string, certificate: ?string}
     * @throws ZatcaException If required is true and credentials are missing/invalid
     */
    private function getSigningCredentials(string $organizationId, bool $required = false): array
    {
        $path = "zatca/{$organizationId}/pcsid.json";

        if (!Storage::disk('local')->exists($path)) {
            if ($required) {
                throw ZatcaException::missingCredentials(
                    'PCSID credentials not found. Organization must complete ZATCA onboarding ' .
                    'to obtain Production CSID (PCSID) before submitting invoices.',
                    [
                        'organization_id' => $organizationId,
                        'expected_path' => $path,
                    ]
                );
            }
            return ['privateKey' => null, 'certificate' => null];
        }

        try {
            $content = Storage::disk('local')->get($path);
            $data = json_decode(decrypt($content), true);

            $privateKey = $data['privateKey'] ?? null;
            $certificate = $data['pcsid'] ?? null;

            // Validate credentials are not empty when required
            if ($required && (empty($privateKey) || empty($certificate))) {
                throw ZatcaException::invalidCredentials(
                    'PCSID credentials are incomplete or corrupted. ' .
                    'Please re-run Step 3 of ZATCA onboarding to obtain valid PCSID.',
                    [
                        'organization_id' => $organizationId,
                        'has_private_key' => !empty($privateKey),
                        'has_certificate' => !empty($certificate),
                    ]
                );
            }

            return [
                'privateKey' => $privateKey,
                'certificate' => $certificate,
            ];
        } catch (ZatcaException $e) {
            throw $e;
        } catch (\Exception $e) {
            if ($required) {
                throw ZatcaException::invalidCredentials(
                    'Failed to decrypt PCSID credentials. The credentials may be corrupted ' .
                    'or the application encryption key has changed.',
                    [
                        'organization_id' => $organizationId,
                        'error' => $e->getMessage(),
                    ]
                );
            }
            return ['privateKey' => null, 'certificate' => null];
        }
    }
}
