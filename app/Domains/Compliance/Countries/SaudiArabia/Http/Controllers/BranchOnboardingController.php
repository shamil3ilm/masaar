<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers;

use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\CertificateException;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\ComplianceSampleSet;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\CsidOnboarding;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\OnboardingEvents;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\RenewalGuard;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CertificatePem;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Services\BranchService;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Branch ZATCA Onboarding API controller.
 *
 * Handles ZATCA onboarding for individual branches (EGS units).
 * Each branch goes through the 3-step onboarding process independently.
 */
class BranchOnboardingController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly BranchService $branchService,
        private readonly CsidOnboarding $onboarding,
        private readonly ComplianceSampleSet $samples,
        private readonly OnboardingEvents $events,
        private readonly RenewalGuard $renewal,
    ) {}

    /**
     * Step 1: Request Compliance CSID for branch.
     *
     * POST /api/organizations/branches/{branch}/onboarding/ccsid
     *
     * Requires OTP from ZATCA portal (valid for 1 hour).
     * This is a LIVE process - OTP expires quickly.
     */
    public function requestCcsid(Request $request, string $branchId): JsonResponse
    {
        $request->validate([
            'otp' => ['required', 'string', 'size:6', 'regex:/^\d{6}$/'],
        ]);

        $organization = $this->tenant->getOrganization();
        $branch = $organization->branches()->find($branchId);

        if (! $branch) {
            return ApiResponse::error('Branch not found', 404);
        }

        if (! $organization->hasCompleteZatcaProfile()) {
            return ApiResponse::error(
                'Organization profile incomplete. Ensure VAT number and address are set.',
                422
            );
        }

        // Check if already has CCSID
        if ($this->branchService->getCredentials($branch, 'ccsid')) {
            return ApiResponse::error(
                'Branch already has CCSID. Proceed to compliance check or request new OTP to re-onboard.',
                422
            );
        }

        $csrData = $branch->getCsrData();

        try {
            // This is a LIVE call to ZATCA - OTP must be valid
            $result = $this->onboarding->requestComplianceCsid($csrData, $request->otp);

            // Store credentials for this branch
            $this->branchService->storeCredentials($branch, 'ccsid', $result);

            // Update branch status
            $branch->update(['onboarding_status' => Branch::STATUS_CSR_GENERATED]);
            $this->events->announce((string) $organization->id, OnboardingEvents::CCSID_OBTAINED, (string) $branch->id);

            return ApiResponse::success([
                'ccsid' => substr($result['ccsid'], 0, 50).'...',
                'request_id' => $result['requestId'],
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
                'next_step' => 'POST /api/organizations/branches/'.$branch->id.'/onboarding/compliance-check',
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
     * Step 2: Run compliance checks for branch.
     *
     * POST /api/organizations/branches/{branch}/onboarding/compliance-check
     *
     * Submits 6 test invoices to ZATCA to verify compliance.
     */
    public function runComplianceCheck(Request $request, string $branchId): JsonResponse
    {
        $organization = $this->tenant->getOrganization();
        $branch = $organization->branches()->find($branchId);

        if (! $branch) {
            return ApiResponse::error('Branch not found', 404);
        }

        $credentials = $this->branchService->getCredentials($branch, 'ccsid');

        if (! $credentials) {
            return ApiResponse::error('No CCSID found. Complete step 1 first.', 422);
        }

        // Generate test invoices for this branch
        $testInvoices = $this->generateTestInvoices($organization, $branch);

        try {
            $result = $this->onboarding->runComplianceChecks(
                $credentials['ccsid'],
                $credentials['secret'],
                $testInvoices
            );

            if ($result['passed']) {
                $branch->update(['onboarding_status' => Branch::STATUS_COMPLIANCE_PASSED]);
                $this->events->announce((string) $organization->id, OnboardingEvents::COMPLIANCE_PASSED, (string) $branch->id);
            }

            return ApiResponse::success([
                'passed' => $result['passed'],
                'results' => $result['results'],
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
                'next_step' => $result['passed']
                    ? 'POST /api/organizations/branches/'.$branch->id.'/onboarding/pcsid'
                    : 'Fix errors and retry compliance check',
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
     * Step 3: Request Production CSID for branch.
     *
     * POST /api/organizations/branches/{branch}/onboarding/pcsid
     */
    public function requestPcsid(Request $request, string $branchId): JsonResponse
    {
        $organization = $this->tenant->getOrganization();
        $branch = $organization->branches()->find($branchId);

        if (! $branch) {
            return ApiResponse::error('Branch not found', 404);
        }

        $credentials = $this->branchService->getCredentials($branch, 'ccsid');

        if (! $credentials) {
            return ApiResponse::error('No CCSID found. Complete steps 1-2 first.', 422);
        }

        try {
            // A certificate already on disk means this step has run and only
            // its last write did not.
            //
            // The file and the row cannot be one transaction - one is a file
            // and one is a row - so the failure is made recoverable instead.
            // Writing the credential and then marking the branch active left
            // a branch holding a valid production certificate while
            // onboarding_status stayed compliance_passed, so isFatooraReady()
            // was false and Submitter refused every invoice from it. Running
            // the step again meant another OTP from the ZATCA Fatoora portal,
            // because the first one was spent buying the certificate that was
            // already sitting there unused.
            //
            // So: if the credential is present, finish the row and do not ask
            // the authority for a second certificate.
            $existing = $this->branchService->getCredentials($branch, 'pcsid');

            if (! empty($existing['pcsid'])) {
                $branch->markAsActive($this->parseCertificateExpiry($existing['pcsid']));

                return ApiResponse::success([
                    'branch_id' => $branch->id,
                    'branch_name' => $branch->name,
                    'onboarded' => true,
                    'cert_expires_at' => $branch->fresh()->cert_expires_at?->toIso8601String(),
                ], 'Production CSID was already obtained. The branch is now marked ready.');
            }

            $result = $this->onboarding->requestProductionCsid(
                $credentials['ccsid'],
                $credentials['secret'],
                $credentials['requestId']
            );

            // Store production credentials
            $this->branchService->storeCredentials($branch, 'pcsid', array_merge($result, [
                'privateKey' => $credentials['privateKey'],
            ]));

            // Parse certificate expiry
            $certificateExpiry = null;
            if (isset($result['pcsid'])) {
                $certificateExpiry = $this->parseCertificateExpiry($result['pcsid']);
            }

            // Mark branch as active
            $branch->markAsActive($certificateExpiry);
            $this->events->announce((string) $organization->id, OnboardingEvents::PCSID_OBTAINED, (string) $branch->id);

            return ApiResponse::success([
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
                'onboarded' => true,
                'cert_expires_at' => $certificateExpiry?->toIso8601String(),
            ], 'Production CSID obtained. Branch is now ZATCA compliant and ready for invoicing.');

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
     * Re-onboard branch with new OTP.
     *
     * POST /api/organizations/branches/{branch}/onboarding/reset
     *
     * Use this when certificate expires or needs renewal.
     */
    public function resetOnboarding(Request $request, string $branchId): JsonResponse
    {
        $organization = $this->tenant->getOrganization();
        $branch = $organization->branches()->find($branchId);

        if (! $branch) {
            return ApiResponse::error('Branch not found', 404);
        }

        // A reset is how a branch renews, and a renewal waits while documents
        // the current certificate signed are unfiled (RenewalGuard) - unless
        // a reason is given, which is audited.
        $override = $request->validate(['renewal_override_reason' => ['nullable', 'string', 'max:500']]);

        try {
            $this->renewal->assertMayRenew((string) $organization->id, (string) $branch->id, $override['renewal_override_reason'] ?? null);
        } catch (DomainException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        }

        // The row first, the files second.
        //
        // This was the other way round, and the two cannot be one transaction.
        // Deleting the credentials first and then dying left a branch whose
        // status still said active with a future cert_expires_at - so
        // isFatooraReady() was true and the admin console reported it as
        // onboarded - while its private key was gone from disk. Every
        // submission from it then failed with missingCredentials, and nothing
        // on the branch said why.
        //
        // In this order the same failure leaves a branch marked pending with
        // its credentials still present, which signs nothing because the
        // status gates it, and which running the reset again cleans up.
        $branch->update([
            'onboarding_status' => Branch::STATUS_PENDING,
            'cert_expires_at' => null,
        ]);

        $this->branchService->deleteCredentials($branch);

        return ApiResponse::success([
            'branch_id' => $branch->id,
            'next_step' => 'POST /api/organizations/branches/'.$branch->id.'/onboarding/ccsid',
        ], 'Onboarding reset. Obtain a new OTP from the ZATCA Fatoora Portal to start fresh.');
    }

    /**
     * Generate test invoices for compliance check.
     */
    private function generateTestInvoices($organization, Branch $branch): array
    {
        $documents = $this->samples->build(
            sellerName: $organization->name,
            sellerVatNumber: $organization->vat_number ?? '',
            sellerCrNumber: $organization->cr_number,
            sellerAddress: $branch->getAddressData(),
            numberPrefix: 'TEST-'.$branch->id,
        );

        return array_map(fn (array $document): string => $document['xml'], $documents);
    }

    /**
     * Parse certificate expiry from PEM.
     */
    private function parseCertificateExpiry(string $certificate): ?\DateTime
    {
        try {
            // Stored as ZATCA issues it - a token, not a PEM - which this read
            // as nothing, so a branch was activated with no expiry on record.
            $cert = openssl_x509_read((string) CertificatePem::from($certificate));
            if (! $cert) {
                return null;
            }

            $certInfo = openssl_x509_parse($cert);
            if (! $certInfo || ! isset($certInfo['validTo_time_t'])) {
                return null;
            }

            return (new \DateTime)->setTimestamp($certInfo['validTo_time_t']);
        } catch (\Exception $e) {
            // A certificate this cannot read has no expiry to report, which
            // the caller handles. There was a CertificateException arm here
            // returning ApiResponse::fromCertificate - added by a replace
            // across this file that assumed every catch in it belonged to a
            // controller action. This one does not: the method is declared
            // ?\DateTime, so that arm was a TypeError waiting for a
            // certificate bad enough to reach it.
            return null;
        }
    }
}
