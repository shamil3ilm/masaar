<?php

declare(strict_types=1);

namespace App\Domains\Organization\Http\Controllers;

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
    ) {}

    /**
     * List all branches for the organization.
     *
     * GET /api/organizations/branches
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->tenant->getOrganization();

        $query = $organization->branches();

        // Filter by status
        if ($request->has('status')) {
            $query->where('onboarding_status', $request->status);
        }

        // Filter by active only
        if ($request->boolean('active_only')) {
            $query->active();
        }

        // Filter by zatca_ready
        if ($request->boolean('zatca_ready')) {
            $query->fatooraReady();
        }

        $branches = $query->orderBy('is_default', 'desc')
            ->orderBy('name')
            ->get()
            ->map(fn (Branch $branch) => $this->formatBranch($branch));

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
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'industry' => ['nullable', 'string', 'max:100'],
            'street' => ['required', 'string', 'max:255'],
            'building_number' => ['required', 'string', 'size:4', 'regex:/^\d{4}$/'],
            'additional_number' => ['nullable', 'string', 'size:4', 'regex:/^\d{4}$/'],
            'district' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'postal_code' => ['required', 'string', 'size:5', 'regex:/^\d{5}$/'],
            'device_serial' => ['nullable', 'string', 'max:255', 'unique:branches,device_serial'],
        ]);

        $organization = $this->tenant->getOrganization();

        try {
            $branch = $this->branchService->create($organization, $validated);

            return ApiResponse::success([
                'branch' => $this->formatBranch($branch),
                'message' => 'Branch created successfully. Complete ZATCA onboarding to start issuing invoices.',
            ], 201);

        } catch (\Exception $e) {
            return ApiResponse::error('Failed to create branch: '.$e->getMessage(), 422);
        }
    }

    /**
     * Get branch details.
     *
     * GET /api/organizations/branches/{branch}
     */
    public function show(string $branchId): JsonResponse
    {
        $organization = $this->tenant->getOrganization();

        $branch = $organization->branches()->find($branchId);

        if (! $branch) {
            return ApiResponse::error('Branch not found', 404);
        }

        return ApiResponse::success([
            'branch' => $this->formatBranch($branch, detailed: true),
        ]);
    }

    /**
     * Update branch details.
     *
     * PUT /api/organizations/branches/{branch}
     */
    public function update(Request $request, string $branchId): JsonResponse
    {
        $organization = $this->tenant->getOrganization();

        $branch = $organization->branches()->find($branchId);

        if (! $branch) {
            return ApiResponse::error('Branch not found', 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'industry' => ['nullable', 'string', 'max:100'],
            'street' => ['sometimes', 'string', 'max:255'],
            'building_number' => ['sometimes', 'string', 'size:4', 'regex:/^\d{4}$/'],
            'additional_number' => ['nullable', 'string', 'size:4', 'regex:/^\d{4}$/'],
            'district' => ['sometimes', 'string', 'max:100'],
            'city' => ['sometimes', 'string', 'max:100'],
            'postal_code' => ['sometimes', 'string', 'size:5', 'regex:/^\d{5}$/'],
        ]);

        try {
            $branch = $this->branchService->update($branch, $validated);

            return ApiResponse::success([
                'branch' => $this->formatBranch($branch),
                'message' => 'Branch updated successfully.',
            ]);

        } catch (\Exception $e) {
            return ApiResponse::error('Failed to update branch: '.$e->getMessage(), 422);
        }
    }

    /**
     * Delete a branch.
     *
     * DELETE /api/organizations/branches/{branch}
     */
    public function destroy(string $branchId): JsonResponse
    {
        $organization = $this->tenant->getOrganization();

        $branch = $organization->branches()->find($branchId);

        if (! $branch) {
            return ApiResponse::error('Branch not found', 404);
        }

        try {
            $this->branchService->delete($branch);

            return ApiResponse::success([
                'message' => 'Branch deleted successfully.',
            ]);

        } catch (\Exception $e) {
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

        $branch = $organization->branches()->find($branchId);

        if (! $branch) {
            return ApiResponse::error('Branch not found', 404);
        }

        $branch->setAsDefault();

        return ApiResponse::success([
            'branch' => $this->formatBranch($branch),
            'message' => 'Branch set as default.',
        ]);
    }

    /**
     * Suspend a branch.
     *
     * POST /api/organizations/branches/{branch}/suspend
     */
    public function suspend(string $branchId): JsonResponse
    {
        $organization = $this->tenant->getOrganization();

        $branch = $organization->branches()->find($branchId);

        if (! $branch) {
            return ApiResponse::error('Branch not found', 404);
        }

        $branch->suspend();

        return ApiResponse::success([
            'branch' => $this->formatBranch($branch),
            'message' => 'Branch suspended.',
        ]);
    }

    /**
     * Reactivate a suspended branch.
     *
     * POST /api/organizations/branches/{branch}/reactivate
     */
    public function reactivate(string $branchId): JsonResponse
    {
        $organization = $this->tenant->getOrganization();

        $branch = $organization->branches()->find($branchId);

        if (! $branch) {
            return ApiResponse::error('Branch not found', 404);
        }

        if ($branch->onboarding_status === Branch::STATUS_REVOKED) {
            return ApiResponse::error('Cannot reactivate revoked branch. Re-onboard instead.', 422);
        }

        if (! $this->branchService->hasPcsid($branch)) {
            return ApiResponse::error('Branch has no PCSID. Complete onboarding first.', 422);
        }

        $branch->update([
            'onboarding_status' => Branch::STATUS_ACTIVE,
            'is_active' => true,
        ]);

        return ApiResponse::success([
            'branch' => $this->formatBranch($branch),
            'message' => 'Branch reactivated.',
        ]);
    }

    /**
     * Get branch onboarding status.
     *
     * GET /api/organizations/branches/{branch}/onboarding-status
     */
    public function onboardingStatus(string $branchId): JsonResponse
    {
        $organization = $this->tenant->getOrganization();

        $branch = $organization->branches()->find($branchId);

        if (! $branch) {
            return ApiResponse::error('Branch not found', 404);
        }

        $hasCcsid = $this->branchService->getCredentials($branch, 'ccsid') !== null;
        $hasPcsid = $this->branchService->getCredentials($branch, 'pcsid') !== null;

        return ApiResponse::success([
            'branch_id' => $branch->id,
            'branch_name' => $branch->name,
            'onboarding_status' => $branch->onboarding_status,
            'has_ccsid' => $hasCcsid,
            'has_pcsid' => $hasPcsid,
            'is_fatoora_ready' => $branch->isFatooraReady(),
            'certificate_expires_at' => $branch->certificate_expires_at?->toIso8601String(),
            'days_until_expiry' => $branch->getDaysUntilCertificateExpiry(),
            'steps' => [
                'step_1_ccsid' => $hasCcsid ? 'completed' : 'pending',
                'step_2_compliance' => $branch->onboarding_status !== Branch::STATUS_PENDING
                    ? ($hasPcsid ? 'completed' : 'in_progress')
                    : 'pending',
                'step_3_pcsid' => $hasPcsid ? 'completed' : 'pending',
            ],
            'next_action' => $this->getNextAction($branch, $hasCcsid, $hasPcsid),
        ]);
    }

    /**
     * Format branch for API response.
     */
    private function formatBranch(Branch $branch, bool $detailed = false): array
    {
        $data = [
            'id' => $branch->id,
            'name' => $branch->name,
            'name_ar' => $branch->name_ar,
            'device_serial' => $branch->device_serial,
            'onboarding_status' => $branch->onboarding_status,
            'is_fatoora_ready' => $branch->isFatooraReady(),
            'is_active' => $branch->is_active,
            'is_default' => $branch->is_default,
            'invoice_count' => $branch->invoice_count,
        ];

        if ($detailed) {
            $data = array_merge($data, [
                'industry' => $branch->industry,
                'address' => [
                    'street' => $branch->street,
                    'building_number' => $branch->building_number,
                    'additional_number' => $branch->additional_number,
                    'district' => $branch->district,
                    'city' => $branch->city,
                    'postal_code' => $branch->postal_code,
                    'country_code' => $branch->country_code,
                ],
                'certificate_expires_at' => $branch->certificate_expires_at?->toIso8601String(),
                'days_until_expiry' => $branch->getDaysUntilCertificateExpiry(),
                'is_certificate_expiring_soon' => $branch->isCertificateExpiringSoon(),
                'onboarded_at' => $branch->onboarded_at?->toIso8601String(),
                'last_invoice_at' => $branch->last_invoice_at?->toIso8601String(),
                'created_at' => $branch->created_at->toIso8601String(),
                'updated_at' => $branch->updated_at->toIso8601String(),
            ]);
        }

        return $data;
    }

    /**
     * Get next action hint for onboarding.
     */
    private function getNextAction(Branch $branch, bool $hasCcsid, bool $hasPcsid): string
    {
        if ($branch->onboarding_status === Branch::STATUS_ACTIVE) {
            return 'Ready to issue invoices';
        }

        if ($branch->onboarding_status === Branch::STATUS_REVOKED) {
            return 'Re-onboard to obtain new certificate';
        }

        if (! $hasCcsid) {
            return 'Obtain OTP from ZATCA Fatoora Portal and call POST /onboarding/ccsid';
        }

        if (! $hasPcsid) {
            return 'Run compliance check and call POST /onboarding/pcsid';
        }

        return 'Unknown state';
    }
}
