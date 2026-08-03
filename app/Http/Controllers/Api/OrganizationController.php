<?php

namespace App\Http\Controllers\Api;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Organization\Models\Organization;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Organization management API controller.
 */
class OrganizationController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}
    /**
     * List user's organizations.
     *
     * GET /api/organizations
     */
    public function index(): JsonResponse
    {
        $organizations = auth()->user()->organizations;

        return ApiResponse::success([
            'organizations' => $organizations,
        ]);
    }

    /**
     * Create a new organization.
     *
     * POST /api/organizations
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'size:2'],
            'vat_number' => ['nullable', 'string', 'max:50'],
        ]);

        $organization = Organization::create([
            'name' => $request->name,
            'country' => $request->country ?? 'SA',
            'status' => 'active',
            'compliance_profile' => [
                'vat_number' => $request->vat_number,
            ],
        ]);

        // Attach user as admin
        auth()->user()->organizations()->attach($organization->id, [
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->audit->logCreated($organization);

        return ApiResponse::created([
            'organization' => $organization,
        ], 'Organization created');
    }

    /**
     * Get organization details.
     *
     * GET /api/organizations/{id}
     */
    public function show(string $id): JsonResponse
    {
        $organization = auth()->user()->organizations()->findOrFail($id);

        return ApiResponse::success([
            'organization' => $organization,
        ]);
    }

    /**
     * Update organization.
     *
     * PUT /api/organizations/{id}
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $organization = auth()->user()->organizations()
            ->wherePivot('role', 'admin')
            ->findOrFail($id);

        $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'vat_number' => ['nullable', 'string', 'max:50'],
        ]);

        $oldValues = $organization->toArray();

        $organization->update([
            'name' => $request->name ?? $organization->name,
            'compliance_profile' => array_merge(
                $organization->compliance_profile ?? [],
                array_filter(['vat_number' => $request->vat_number])
            ),
        ]);

        $this->audit->logUpdated($organization, $oldValues);

        return ApiResponse::success([
            'organization' => $organization->fresh(),
        ], 'Organization updated');
    }

    /**
     * Switch active organization context.
     *
     * POST /api/organizations/{id}/switch
     */
    public function switch(string $id): JsonResponse
    {
        $membership = auth()->user()->organizations()->findOrFail($id)->pivot;

        // Set tenant context
        $resolver = app(\App\Domains\Organization\Services\TenantResolver::class);
        $resolver->setContext(new \App\Domains\Organization\ValueObjects\OrganizationContext(
            organizationId: $id,
            role: $membership->role,
        ));

        return ApiResponse::success([
            'organization_id' => $id,
            'role' => $membership->role,
        ], 'Organization switched');
    }
}
