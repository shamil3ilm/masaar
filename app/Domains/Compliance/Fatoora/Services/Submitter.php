<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Compliance\Fatoora\Client\FatooraClient;
use App\Domains\Compliance\Fatoora\DTOs\FatooraResponse;
use App\Domains\Compliance\Fatoora\Exceptions\FatooraException;
use App\Domains\Invoice\Enums\InvoiceStatus;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Enums\LicenseEnvironment;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\BranchService;
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
class Submitter
{
    public function __construct(
        private readonly DocumentBuilder $compliance,
        private readonly FatooraClient $client,
        private readonly AuditService $audit,
        private readonly ?CertificateService $certificateService = null,
        private readonly ?BranchService $branchService = null,
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

        // Update invoice with compliance data (including signed_xml when available)
        $invoice->update([
            'hash' => $complianceData['hash'],
            'qr_code' => $complianceData['qr_code'],
            'signed_xml' => $complianceData['signed_xml'] ?? null,
            'status' => InvoiceStatus::Issued,
        ]);

        return [
            'hash' => $complianceData['hash'],
            'qr_code' => $complianceData['qr_code'],
            'signed_xml' => $complianceData['signed_xml'] ?? null,
        ];
    }

    /**
     * Validate invoice with ZATCA (without submission).
     */
    public function validate(Invoice $invoice, Organization $organization): FatooraResponse
    {
        $previousHash = $this->getPreviousInvoiceHash($invoice);
        $credentials = $this->getSigningCredentials($organization->id);

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

        // Get credentials - branch-level if available, otherwise organization-level.
        // $invoice->branch may be null if Invoice has no branch_id column (use org-level credentials).
        $branch = isset($invoice->branch_id) ? $invoice->branch : null;
        $credentials = $this->getSigningCredentials($organization->id, $branch, required: true);

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

        $complianceData = $this->compliance->generateComplianceData(
            invoice: $invoice,
            organization: $organization,
            privateKey: $credentials['privateKey'] ?? null,
            certificate: $credentials['certificate'] ?? null,
        );

        // Choose clearance (B2B) or reporting (B2C) based on invoice type
        if ($invoice->requiresClearance()) {
            // B2B: Submit for clearance (no deadline)
            $response = $this->client->clearInvoice(
                invoiceXml: $complianceData['xml'],
                invoiceHash: $complianceData['hash'],
                uuid: $invoice->id,
            );
        } else {
            // B2C: Report invoice - ZATCA requires reporting within 24 hours
            $this->validateReportingDeadline($invoice);
            $response = $this->client->reportInvoice(
                invoiceXml: $complianceData['xml'],
                invoiceHash: $complianceData['hash'],
                uuid: $invoice->id,
            );
        }

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
     * @throws FatooraException If certificate is invalid, expired, or revoked
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
     * Validate B2C invoice reporting deadline.
     *
     * COMPLIANCE: ZATCA requires simplified (B2C) invoices to be reported
     * within 24 hours of issuance. This method enforces that deadline.
     *
     * @throws FatooraException If invoice is older than 24 hours
     */
    private function validateReportingDeadline(Invoice $invoice): void
    {
        // Get the deadline hours from config (default 24 hours per ZATCA)
        $deadlineHours = config('fatoora.reporting.deadline_hours', 24);

        // Skip deadline check if explicitly disabled
        if (! config('fatoora.reporting.enforce_deadline', true)) {
            return;
        }

        $issueDate = $invoice->issue_date;
        if (! $issueDate instanceof \DateTimeInterface) {
            $issueDate = new \DateTime($issueDate);
        }

        $now = new \DateTime;
        $ageHours = ($now->getTimestamp() - $issueDate->getTimestamp()) / 3600;

        if ($ageHours > $deadlineHours) {
            throw FatooraException::validation(
                sprintf(
                    'B2C invoice reporting deadline exceeded. Invoice was issued %.1f hours ago. '.
                    'ZATCA requires simplified invoices to be reported within %d hours of issuance.',
                    $ageHours,
                    $deadlineHours
                ),
                context: [
                    'invoice_id' => $invoice->id,
                    'issue_date' => $issueDate->format('Y-m-d H:i:s'),
                    'age_hours' => round($ageHours, 2),
                    'deadline_hours' => $deadlineHours,
                ]
            );
        }

        // Log warning if approaching deadline (>20 hours)
        if ($ageHours > ($deadlineHours * 0.8)) {
            Log::warning('B2C invoice approaching reporting deadline', [
                'invoice_id' => $invoice->id,
                'age_hours' => round($ageHours, 2),
                'deadline_hours' => $deadlineHours,
                'remaining_hours' => round($deadlineHours - $ageHours, 2),
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

    /**
     * Update invoice status after ZATCA response.
     */
    private function updateInvoiceStatus(Invoice $invoice, FatooraResponse $response): void
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

        // Increment branch invoice count if successful
        if ($response->success) {
            $this->incrementBranchInvoiceCount($invoice);
        }
    }

    /**
     * Get hash of previous invoice for PIH chaining.
     *
     * Uses the ICV (Invoice Counter Value) column rather than created_at so
     * that the chain order matches ZATCA's sequential counter, not wall-clock
     * time which can be non-deterministic under concurrent inserts.
     */
    private function getPreviousInvoiceHash(Invoice $invoice): ?string
    {
        $previous = Invoice::where('org_id', $invoice->org_id)
            ->where('icv', '<', $invoice->icv)
            ->whereNotNull('hash')
            ->orderBy('icv', 'desc')
            ->first();

        return $previous?->hash;
    }

    /**
     * Get signing credentials for organization or branch.
     *
     * Supports multi-branch architecture:
     * 1. If branch provided, tries branch credentials first
     * 2. Falls back to organization-level credentials (legacy)
     *
     * @param  string  $organizationId  The organization ID
     * @param  Branch|null  $branch  The branch (optional)
     * @param  bool  $required  If true, throws exception when credentials are missing
     * @return array{privateKey: ?string, certificate: ?string}
     *
     * @throws FatooraException If required is true and credentials are missing/invalid
     */
    private function getSigningCredentials(string $organizationId, ?Branch $branch = null, bool $required = false): array
    {
        // Try branch credentials first if branch is provided and BranchService is available
        if ($branch && $this->branchService) {
            $branchCredentials = $this->branchService->getCredentials($branch, 'pcsid');

            if ($branchCredentials) {
                $privateKey = $branchCredentials['privateKey'] ?? null;
                $certificate = $branchCredentials['pcsid'] ?? null;

                if (! empty($privateKey) && ! empty($certificate)) {
                    Log::debug('Using branch-level credentials', [
                        'org_id' => $organizationId,
                        'branch_id' => $branch->id,
                    ]);

                    return [
                        'privateKey' => $privateKey,
                        'certificate' => $certificate,
                    ];
                }
            }
        }

        // Fall back to organization-level credentials (legacy path)
        $path = "zatca/{$organizationId}/pcsid.json";

        if (! Storage::disk('local')->exists($path)) {
            if ($required) {
                $errorContext = [
                    'org_id' => $organizationId,
                    'expected_path' => $path,
                ];

                if ($branch) {
                    $errorContext['branch_id'] = $branch->id;
                    $errorContext['branch_name'] = $branch->name;
                }

                throw FatooraException::missingCredentials(
                    'PCSID credentials not found. '.
                    ($branch ? 'Branch' : 'Organization').' must complete ZATCA onboarding '.
                    'to obtain Production CSID (PCSID) before submitting invoices.',
                    $errorContext
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
                throw FatooraException::invalidCredentials(
                    'PCSID credentials are incomplete or corrupted. '.
                    'Please re-run Step 3 of ZATCA onboarding to obtain valid PCSID.',
                    [
                        'org_id' => $organizationId,
                        'has_private_key' => ! empty($privateKey),
                        'has_certificate' => ! empty($certificate),
                    ]
                );
            }

            Log::debug('Using organization-level credentials (legacy)', [
                'org_id' => $organizationId,
            ]);

            return [
                'privateKey' => $privateKey,
                'certificate' => $certificate,
            ];
        } catch (FatooraException $e) {
            throw $e;
        } catch (\Exception $e) {
            if ($required) {
                throw FatooraException::invalidCredentials(
                    'Failed to decrypt PCSID credentials. The credentials may be corrupted '.
                    'or the application encryption key has changed.',
                    [
                        'org_id' => $organizationId,
                        'error' => $e->getMessage(),
                    ]
                );
            }

            return ['privateKey' => null, 'certificate' => null];
        }
    }

    /**
     * Increment branch invoice count after successful submission.
     */
    private function incrementBranchInvoiceCount(Invoice $invoice): void
    {
        if ($invoice->branch_id && $invoice->branch) {
            $invoice->branch->incrementInvoiceCount();
        }
    }
}
