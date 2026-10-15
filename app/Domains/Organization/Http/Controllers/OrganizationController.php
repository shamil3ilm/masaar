<?php

namespace App\Domains\Organization\Http\Controllers;

use App\Domains\Organization\DTOs\AddressFields;
use App\Domains\Organization\DTOs\OrganizationChangesData;
use App\Domains\Organization\DTOs\OrganizationData;
use App\Domains\Organization\Rules\CompanyProfileRules;
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
        $country = (string) ($request->input('country') ?: 'SA');

        // The seller's address and registration may come now or later; each is
        // checked against ZATCA's shape when it is sent. Onboarding refuses
        // until all of it is there.
        $validated = $request->validate(
            [
                ...CompanyProfileRules::rules($country, complete: false),
                ...CompanyProfileRules::country(),
                'name' => ['required', 'string', 'max:255'],
            ],
            CompanyProfileRules::messages(),
        );

        $organization = $this->registrar->register(auth()->user(), new OrganizationData(
            name: $validated['name'],
            nameAr: $validated['name_ar'] ?? null,
            country: $country,
            vatNumber: $validated['vat_number'] ?? null,
            address: AddressFields::from($validated),
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

        // The seller's details, in ZATCA's shapes, each checked when sent. The
        // address had no way in at all, so no organization could complete
        // the profile onboarding requires. The VAT number is fixed once the
        // organization is onboarded.
        $validated = $request->validate(
            [
                ...CompanyProfileRules::rules((string) $organization->country, complete: false, existing: $organization),
                // Whether invoices' prices include VAT unless an invoice says
                // otherwise.
                'prices_include_vat' => ['sometimes', 'boolean'],
            ],
            CompanyProfileRules::messages(),
        );

        $organization = $this->registrar->amend($organization, new OrganizationChangesData(
            name: $validated['name'] ?? null,
            nameAr: $validated['name_ar'] ?? null,
            vatNumber: $validated['vat_number'] ?? null,
            pricesIncludeVat: $request->has('prices_include_vat') ? $request->boolean('prices_include_vat') : null,
            address: AddressFields::from($validated),
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
