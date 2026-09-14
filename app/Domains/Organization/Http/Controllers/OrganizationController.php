<?php

namespace App\Domains\Organization\Http\Controllers;

use App\Domains\Organization\DTOs\OrganizationChangesData;
use App\Domains\Organization\DTOs\OrganizationData;
use App\Domains\Organization\Services\Membership;
use App\Domains\Organization\Services\Registrar;
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
        private readonly TenantResolver $tenant,
        private readonly Membership $membership,
        private readonly Registrar $registrar,
    ) {}

    /**
     * List user's organizations.
     *
     * GET /api/organizations
     */
    public function index(): JsonResponse
    {
        return ApiResponse::success([
            'organizations' => $this->membership->memberships(auth()->user()),
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

        $organization = $this->registrar->register(auth()->user(), new OrganizationData(
            name: $request->name,
            country: $request->country ?? 'SA',
            vatNumber: $request->vat_number,
        ));

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
        $organization = $this->membership->organization(auth()->user(), $id);

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
        // Admin is enforced by org.admin on the route, in one place with every
        // other action restricted the same way. That gate reads the role in
        // the organization this session acts for, so only that organization
        // may be changed here; any other would pass on someone else's role.
        if ($id !== $this->tenant->getOrganizationId()) {
            return ApiResponse::forbidden('Switch to this organization before changing it.');
        }

        $organization = $this->membership->organization(auth()->user(), $id);

        $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'vat_number' => ['nullable', 'string', 'max:50'],
        ]);

        $organization = $this->registrar->amend($organization, new OrganizationChangesData(
            name: $request->name,
            vatNumber: $request->vat_number,
        ));

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
     * The lookup among the user's active memberships is the authorization: a
     * caller cannot name an organization they do not belong to.
     */
    public function switch(string $id): JsonResponse
    {
        $user = auth()->user();
        $membership = $this->membership->organization($user, $id)->pivot;

        // The rest of this request is scoped too, so anything the response
        // builds sees the organization that was just chosen.
        $this->tenant->setContext(new OrganizationContext(
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
