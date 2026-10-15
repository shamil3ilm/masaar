<?php

namespace App\Domains\Compliance\Fatoora\Http\Controllers;

use App\Domains\Compliance\Fatoora\DTOs\CsrData;
use App\Domains\Compliance\Fatoora\Exceptions\CertificateException;
use App\Domains\Compliance\Fatoora\Services\ComplianceSampleSet;
use App\Domains\Compliance\Fatoora\Services\CredentialStore;
use App\Domains\Compliance\Fatoora\Services\CsidOnboarding;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\Registrar;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ZATCA Onboarding API controller.
 *
 * Handles the ZATCA onboarding flow:
 * 1. Generate CSR
 * 2. Get Compliance CSID (CCSID)
 * 3. Run compliance checks
 * 4. Get Production CSID (PCSID)
 */
class OnboardingController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly CsidOnboarding $onboarding,
        private readonly CredentialStore $credentials,
        private readonly Registrar $registrar,
        private readonly ComplianceSampleSet $samples,
    ) {}

    /**
     * Step 1: Request Compliance CSID.
     *
     * POST /api/compliance/onboarding/ccsid
     *
     * Requires OTP from ZATCA portal (valid for 1 hour).
     */
    public function requestCcsid(Request $request): JsonResponse
    {
        $request->validate([
            'otp' => ['required', 'string', 'size:6'],
            'common_name' => ['required', 'string', 'max:255'],
            'serial_number' => ['required', 'string', 'max:50'],
            'organization_unit' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'industry' => ['nullable', 'string', 'max:100'],
        ]);

        $organization = $this->tenant->getOrganization();

        if (! $organization->hasCompleteZatcaProfile()) {
            return ApiResponse::error('Organization profile incomplete. Ensure VAT number and address are set.', 422);
        }

        $csrData = new CsrData(
            organizationName: $organization->name,
            organizationUnit: $request->organization_unit ?? 'Main Branch',
            commonName: $request->common_name,
            vatNumber: $organization->vat_number,
            serialNumber: $request->serial_number,
            location: $request->location ?? $organization->city ?? '',
            industry: $request->industry ?? 'General',
        );

        try {
            $result = $this->onboarding->requestComplianceCsid($csrData, $request->otp);

            // Store credentials securely
            $this->storeCredentials($organization->id, 'ccsid', $result);

            return ApiResponse::success([
                'ccsid' => substr($result['ccsid'], 0, 50).'...', // Truncate for display
                'request_id' => $result['requestId'],
            ], 'Compliance CSID obtained. Proceed with compliance checks.');

        } catch (CertificateException $e) {
            // Its own code and category, from the one place that knows them.
            // This used to fall through to a broad catch that answered
            // VALIDATION_ERROR, and the prefix added there duplicated the
            // exception's own message - CsidOnboarding already throws
            // 'Failed to obtain CCSID: ...'.
            return ApiResponse::fromCertificate($e);
        }
    }

    /**
     * Step 2: Run compliance checks.
     *
     * POST /api/compliance/onboarding/compliance-check
     */
    public function runComplianceCheck(Request $request): JsonResponse
    {
        $organization = $this->tenant->getOrganization();

        $credentials = $this->getCredentials($organization->id, 'ccsid');

        if (! $credentials) {
            return ApiResponse::error('No CCSID found. Complete step 1 first.', 422);
        }

        // Generate test invoices for compliance check
        $testInvoices = $this->generateTestInvoices($organization);

        try {
            $result = $this->onboarding->runComplianceChecks(
                $credentials['ccsid'],
                $credentials['secret'],
                $testInvoices
            );

            return ApiResponse::success([
                'passed' => $result['passed'],
                'results' => $result['results'],
            ], $result['passed']
                ? 'Compliance checks passed. Proceed to request PCSID.'
                : 'Compliance checks failed. Fix errors and retry.');

        } catch (CertificateException $e) {
            // Its own code and category, from the one place that knows them.
            // This used to fall through to a broad catch that answered
            // VALIDATION_ERROR, and the prefix added there duplicated the
            // exception's own message - CsidOnboarding already throws
            // 'Failed to obtain CCSID: ...'.
            return ApiResponse::fromCertificate($e);
        }
    }

    /**
     * Step 3: Request Production CSID.
     *
     * POST /api/compliance/onboarding/pcsid
     */
    public function requestPcsid(Request $request): JsonResponse
    {
        $organization = $this->tenant->getOrganization();

        $credentials = $this->getCredentials($organization->id, 'ccsid');

        if (! $credentials) {
            return ApiResponse::error('No CCSID found. Complete steps 1-2 first.', 422);
        }

        try {
            // Already have one? Then this step ran and only its last write
            // did not. See the branch controller for the whole reasoning: the
            // credential is a file and the flag is a row, so they cannot be
            // one transaction, and the OTP that bought the certificate is
            // spent. Finishing the row is the recovery; asking ZATCA for a
            // second certificate is not.
            $existing = $this->credentials->get((string) $organization->id, null, CredentialStore::PCSID);

            if (empty($existing['pcsid'])) {
                $result = $this->onboarding->requestProductionCsid(
                    $credentials['ccsid'],
                    $credentials['secret'],
                    $credentials['requestId']
                );

                // Store production credentials
                $this->storeCredentials($organization->id, 'pcsid', array_merge($result, [
                    'privateKey' => $credentials['privateKey'],
                ]));
            }

            // Through the service that owns organization writes, which
            // locks the row and re-reads it. compliance_profile holds several
            // unrelated keys, so a read, merge and write built from a copy
            // loaded earlier discards anything written in between - and
            // Registrar::amend writes the VAT number into that same column.
            // ControllerQueryTest refused the version of this that queried
            // from here, which is the rule doing its job.
            $this->registrar->markOnboarded($organization);

            return ApiResponse::success(
                ['onboarded' => true],
                'Production CSID obtained. Organization is now ZATCA compliant.',
            );

        } catch (CertificateException $e) {
            // Its own code and category, from the one place that knows them.
            // This used to fall through to a broad catch that answered
            // VALIDATION_ERROR, and the prefix added there duplicated the
            // exception's own message - CsidOnboarding already throws
            // 'Failed to obtain CCSID: ...'.
            return ApiResponse::fromCertificate($e);
        }
    }

    /**
     * Get onboarding status.
     *
     * GET /api/compliance/onboarding/status
     */
    public function status(): JsonResponse
    {
        $organization = $this->tenant->getOrganization();

        $hasCcsid = $this->getCredentials($organization->id, 'ccsid') !== null;
        $hasPcsid = $this->getCredentials($organization->id, 'pcsid') !== null;

        return ApiResponse::success([
            'profile_complete' => $organization->hasCompleteZatcaProfile(),
            'vat_number_valid' => $organization->isValidVatNumber(),
            'has_ccsid' => $hasCcsid,
            'has_pcsid' => $hasPcsid,
            'is_onboarded' => $organization->compliance_profile['zatca_onboarded'] ?? false,
            'steps' => [
                'step_1' => $hasCcsid ? 'completed' : 'pending',
                'step_2' => $hasCcsid && ! $hasPcsid ? 'in_progress' : ($hasPcsid ? 'completed' : 'pending'),
                'step_3' => $hasPcsid ? 'completed' : 'pending',
            ],
        ]);
    }

    /**
     * Store credentials securely.
     */
    private function storeCredentials(string $organizationId, string $type, array $data): void
    {
        $this->credentials->put($organizationId, null, $type, $data);
    }

    /**
     * Get stored credentials.
     */
    private function getCredentials(string $organizationId, string $type): ?array
    {
        return $this->credentials->get($organizationId, null, $type);
    }

    /**
     * Generate test invoices for ZATCA compliance check.
     *
     * Creates all 6 required invoice types per ZATCA specifications:
     * 1. Standard Invoice (388, 01)
     * 2. Standard Credit Note (381, 01)
     * 3. Standard Debit Note (383, 01)
     * 4. Simplified Invoice (388, 02)
     * 5. Simplified Credit Note (381, 02)
     * 6. Simplified Debit Note (383, 02)
     *
     * @return array<string, string> Invoice type => XML
     */
    private function generateTestInvoices(Organization $organization): array
    {
        $documents = $this->samples->build(
            sellerName: $organization->name,
            sellerVatNumber: $organization->vat_number ?? '',
            sellerCrNumber: $organization->cr_number,
            sellerAddress: $organization->getAddressData(),
            numberPrefix: 'TEST-'.time(),
        );

        return array_map(fn (array $document): string => $document['xml'], $documents);
    }
}
