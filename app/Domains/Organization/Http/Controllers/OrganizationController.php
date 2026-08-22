<?php

namespace App\Domains\Organization\Http\Controllers;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Organization\ValueObjects\OrganizationContext;
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
        // Admin is enforced by org.admin on the route, in one place with
        // every other action restricted the same way. Scoping the lookup to
        // admin memberships here as well answered 404 for a member editing an
        // organization they can plainly read, which describes the wrong
        // problem.
        $organization = auth()->user()->organizations()->findOrFail($id);

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
     * Choose which organization this session acts for.
     *
     * POST /api/organizations/{id}/switch
     *
     * Returns a new token carrying the choice. Setting it on TenantResolver is
     * not enough on its own — the resolver lives for one request, so the
     * organization was forgotten the moment the response was sent and every
     * later request arrived with no tenant at all. The claim is what survives,
     * and it is what JwtGuard reads.
     *
     * findOrFail on the user's own memberships is the authorization: a caller
     * cannot name an organization they do not belong to.
     */
    public function switch(string $id): JsonResponse
    {
        $user = auth()->user();
        $membership = $user->activeOrganizations()->findOrFail($id)->pivot;

        // The rest of this request is scoped too, so anything the response
        // builds sees the organization that was just chosen.
        app(TenantResolver::class)->setContext(new OrganizationContext(
            organizationId: $id,
            role: $membership->role,
        ));

        $token = auth('api')->claims([
            'org_id' => $id,
            'role' => $membership->role,
        ])->login($user);

        return ApiResponse::success([
            'org_id' => $id,
            'role' => $membership->role,
            'token' => $token,
        ], 'Organization switched');
    }
}
