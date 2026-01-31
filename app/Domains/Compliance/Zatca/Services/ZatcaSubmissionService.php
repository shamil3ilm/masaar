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
     */
    public function submit(Invoice $invoice, Organization $organization): ZatcaResponse
    {
        // Validate environment before submission
        $this->validateEnvironment();

        $credentials = $this->getSigningCredentials($organization->id);

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
     * @return array{privateKey: ?string, certificate: ?string}
     */
    private function getSigningCredentials(string $organizationId): array
    {
        $path = "zatca/{$organizationId}/pcsid.json";

        if (! Storage::disk('local')->exists($path)) {
            return ['privateKey' => null, 'certificate' => null];
        }

        try {
            $content = Storage::disk('local')->get($path);
            $data = json_decode(decrypt($content), true);

            return [
                'privateKey' => $data['privateKey'] ?? null,
                'certificate' => $data['pcsid'] ?? null,
            ];
        } catch (\Exception $e) {
            return ['privateKey' => null, 'certificate' => null];
        }
    }
}
