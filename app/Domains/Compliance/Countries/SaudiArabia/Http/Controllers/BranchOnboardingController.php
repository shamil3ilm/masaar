<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers;

use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\CertificateException;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\RenewalGuard;
use App\Domains\Compliance\Countries\SaudiArabia\Onboarding\UnitOnboarding;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\EgsUnit;
use App\Domains\Organization\Services\BranchService;
use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Organization\Services\Tills;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Branch ZATCA Onboarding API controller.
 *
 * Onboards a branch's first till - the EGS unit under the branch's own id -
 * through the three steps, as UnitOnboarding does any till's. A branch's
 * other tills are onboarded by their own id (UnitController).
 */
class BranchOnboardingController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly BranchService $branchService,
        private readonly UnitOnboarding $units,
        private readonly Tills $tills,
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

        try {
            // This is a LIVE call to ZATCA - OTP must be valid
            $result = $this->units->requestCcsid($organization, $this->firstTill($branch), (string) $request->otp);

            return ApiResponse::success([
                'ccsid' => substr($result['ccsid'], 0, 50).'...',
                'request_id' => $result['request_id'],
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
        } catch (DomainException $e) {
            return ApiResponse::error($e->getMessage(), 422);
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

        if (! $this->branchService->getCredentials($branch, 'ccsid')) {
            return ApiResponse::error('No CCSID found. Complete step 1 first.', 422);
        }

        try {
            $result = $this->units->runComplianceCheck($organization, $this->firstTill($branch));

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
        } catch (DomainException $e) {
            return ApiResponse::error($e->getMessage(), 422);
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
            $already = ! empty($this->branchService->getCredentials($branch, 'pcsid')['pcsid']);

            $this->units->requestPcsid($organization, $this->firstTill($branch));

            return ApiResponse::success([
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
                'onboarded' => true,
                'cert_expires_at' => $branch->fresh()->cert_expires_at?->toIso8601String(),
            ], $already
                ? 'Production CSID was already obtained. The branch is now marked ready.'
                : 'Production CSID obtained. Branch is now ZATCA compliant and ready for invoicing.');

        } catch (CertificateException $e) {
            // Its own code and category, from the one place that knows them.
            // This used to fall through to a broad catch that answered
            // VALIDATION_ERROR, and the prefix added there duplicated the
            // exception's own message - CsidOnboarding already throws
            // 'Failed to obtain CCSID: ...'.
            return ApiResponse::fromCertificate($e);
        } catch (DomainException $e) {
            return ApiResponse::error($e->getMessage(), 422);
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
     * The branch's first till: the unit under the branch's own id.
     */
    private function firstTill(Branch $branch): EgsUnit
    {
        return $this->tills->find((string) $branch->org_id, (string) $branch->id)
            ?? throw new DomainException(__('This branch has no till of its own.'));
    }
}
