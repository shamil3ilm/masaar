<?php

declare(strict_types=1);

namespace App\Domains\Organization\Http\Controllers;

use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Organization\Exceptions\BranchInUseException;
use App\Domains\Organization\Http\Requests\CreateBranchRequest;
use App\Domains\Organization\Http\Requests\UpdateBranchRequest;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Services\BranchService;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Branch management API controller.
 *
 * Handles CRUD operations for organization branches (EGS units).
 */
class BranchController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly BranchService $branchService,
        private readonly JurisdictionResolver $jurisdictions,
    ) {}

    /**
     * List all branches for the organization.
     *
     * GET /api/organizations/branches
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->tenant->getOrganization();

        $branches = $this->branchService
            ->listFiltered(
                $organization,
                $request->has('status') ? (string) $request->status : null,
                $request->boolean('active_only'),
                $request->boolean('zatca_ready')
            )
            ->map(fn (Branch $branch) => $this->branchService->format($branch));

        return ApiResponse::success([
            'branches' => $branches,
            'total' => $branches->count(),
        ]);
    }

    /**
     * Create a new branch.
     *
     * POST /api/organizations/branches
     */
    public function store(CreateBranchRequest $request): JsonResponse
    {
        $organization = $this->tenant->getOrganization();
        $branch = $this->branchService->create($organization, $request->validated());

        return ApiResponse::created(
            ['branch' => $this->branchService->format($branch)],
            'Branch created. Complete the tax authority onboarding to start issuing invoices.',
        );
    }

    /**
     * Get branch details.
     *
     * GET /api/organizations/branches/{branch}
     */
    public function show(string $branchId): JsonResponse
    {
        $organization = $this->tenant->getOrganization();
        $branch = $this->branchService->find((string) $organization->id, $branchId);

        if (! $branch) {
            return ApiResponse::error('Branch not found', 404);
        }

        return ApiResponse::success([
            'branch' => $this->branchService->format($branch, detailed: true),
        ]);
    }

    /**
     * Update branch details.
     *
     * PUT /api/organizations/branches/{branch}
     */
    public function update(UpdateBranchRequest $request, string $branchId): JsonResponse
    {
        $organization = $this->tenant->getOrganization();
        $branch = $this->branchService->find((string) $organization->id, $branchId);

        if (! $branch) {
            return ApiResponse::error('Branch not found', 404);
        }

        $branch = $this->branchService->update($branch, $request->validated());

        return ApiResponse::success(
            ['branch' => $this->branchService->format($branch)],
            'Branch updated',
        );
    }

    /**
     * Delete a branch.
     *
     * DELETE /api/organizations/branches/{branch}
     */
    public function destroy(string $branchId): JsonResponse
    {
        $organization = $this->tenant->getOrganization();
        $branch = $this->branchService->find((string) $organization->id, $branchId);

        if (! $branch) {
            return ApiResponse::error('Branch not found', 404);
        }

        try {
            $this->branchService->delete($branch);

            return ApiResponse::success(null, 'Branch deleted');
        } catch (BranchInUseException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    /**
     * Set branch as default.
     *
     * POST /api/organizations/branches/{branch}/set-default
     */
    public function setDefault(string $branchId): JsonResponse
    {
        $organization = $this->tenant->getOrganization();
        $branch = $this->branchService->find((string) $organization->id, $branchId);

        if (! $branch) {
            return ApiResponse::error('Branch not found', 404);
        }

        $branch->setAsDefault();

        return ApiResponse::success(
            ['branch' => $this->branchService->format($branch)],
            'Branch set as default',
        );
    }

    /**
     * Suspend a branch.
     *
     * POST /api/organizations/branches/{branch}/suspend
     */
    public function suspend(string $branchId): JsonResponse
    {
        $organization = $this->tenant->getOrganization();
        $branch = $this->branchService->find((string) $organization->id, $branchId);

        if (! $branch) {
            return ApiResponse::error('Branch not found', 404);
        }

        $branch->suspend();

        return ApiResponse::success(
            ['branch' => $this->branchService->format($branch)],
            'Branch suspended',
        );
    }

    /**
     * Reactivate a suspended branch.
     *
     * POST /api/organizations/branches/{branch}/reactivate
     */
    public function reactivate(string $branchId): JsonResponse
    {
        $organization = $this->tenant->getOrganization();
        $branch = $this->branchService->find((string) $organization->id, $branchId);

        if (! $branch) {
            return ApiResponse::error('Branch not found', 404);
        }

        if ($branch->onboarding_status === Branch::STATUS_REVOKED) {
            return ApiResponse::error('Cannot reactivate revoked branch. Re-onboard instead.', 422);
        }

        if (! $this->jurisdictions->forRules($organization)->devices()->holds((string) $organization->id, (string) $branch->id, 'pcsid')) {
            return ApiResponse::error('Branch has no PCSID. Complete onboarding first.', 422);
        }

        $branch->update([
            'onboarding_status' => Branch::STATUS_ACTIVE,
            'is_active' => true,
        ]);

        return ApiResponse::success(
            ['branch' => $this->branchService->format($branch)],
            'Branch reactivated',
        );
    }

    /**
     * Get branch onboarding status.
     *
     * GET /api/organizations/branches/{branch}/onboarding-status
     */
    public function onboardingStatus(string $branchId): JsonResponse
    {
        $organization = $this->tenant->getOrganization();
        $branch = $this->branchService->find((string) $organization->id, $branchId);

        if (! $branch) {
            return ApiResponse::error('Branch not found', 404);
        }

        return ApiResponse::success(
            $this->branchService->onboardingSummary($organization, $branch)
        );
    }
}
