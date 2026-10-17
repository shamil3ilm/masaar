<?php

declare(strict_types=1);

namespace App\Domains\Organization\Http\Controllers;

use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Organization\Http\Middleware\PortalTenant;
use App\Domains\Organization\Services\Membership;
use App\Domains\Organization\Services\SetupChecklist;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Customer Portal Controller.
 *
 * Provides a tenant-scoped dashboard for partner customers.
 *
 * The organization is resolved by PortalTenant from the authenticated
 * session's active memberships. Nothing here may read a tenant identifier from
 * query, body or header — doing so reopens cross-tenant disclosure.
 *
 * PortalTenant puts the resolved tenant into TenantResolver, so the reports
 * called here are confined to it by BelongsToTenant's global scope rather than
 * by a filter each of them has to remember.
 *
 * Below the setup steps the dashboard is the organization's country's
 * (Dashboard::portal): what it files, and how that went.
 */
class CustomerPortalController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly Membership $membership,
        private readonly SetupChecklist $setup,
        private readonly JurisdictionResolver $jurisdictions,
    ) {}

    /**
     * The organization the authenticated user is currently viewing.
     *
     * Null only when a multi-organization user has not chosen one yet; the
     * middleware has already rejected any organization they cannot access.
     */
    private function getOrganizationId(Request $request): ?string
    {
        return $request->attributes->get(PortalTenant::ORG_ID);
    }

    /**
     * Selection screen shown when no organization is resolved for this session.
     *
     * It lists the user's own organizations only.
     */
    private function organizationPicker(?string $error = null): View
    {
        return view('portal.select-org', [
            'organizations' => $this->membership->organizations(Auth::user()),
            'error' => $error,
        ]);
    }

    /**
     * Drop the current organization selection and offer the user's own list.
     */
    public function switchOrganization(Request $request): View
    {
        $request->session()->forget('portal_org_id');

        return $this->organizationPicker();
    }

    /**
     * Customer dashboard - overview of their compliance status.
     */
    public function dashboard(Request $request): View
    {
        $orgId = $this->getOrganizationId($request);

        if (! $orgId) {
            return $this->organizationPicker();
        }

        $organization = $this->tenant->getOrganization();

        if (! $organization) {
            return $this->organizationPicker(__('Organization not found'));
        }

        $country = $this->jurisdictions->forRules($organization)->dashboard();
        $panel = $country->portal($organization);
        $setup = $this->setup->steps($organization);

        return view('portal.dashboard', [
            ...$panel->data,
            'organization' => $organization,
            'heading' => $country->heading(),
            'countryDashboard' => $panel->view,
            'setup' => $setup,
            'settingUp' => $this->setup->unfinished($setup),
        ]);
    }
}
