<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers;

use App\Domains\Compliance\Countries\SaudiArabia\DTOs\SubmissionFilterData;
use App\Domains\Compliance\Countries\SaudiArabia\Reports\Dashboard;
use App\Domains\Compliance\Countries\SaudiArabia\Reports\SubmissionReport;
use App\Domains\Organization\Http\Middleware\PortalTenant;
use App\Domains\Organization\Services\Membership;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * The portal's ZATCA screens: submissions, filterable by person, each
 * person's activity, and the certificate the organization signs with.
 *
 * The organization is resolved by PortalTenant from the session's active
 * memberships; nothing here reads a tenant identifier from query, body or
 * header. The reports are confined to it by BelongsToTenant's global scope.
 */
class PortalSubmissionController extends Controller
{
    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly Membership $membership,
        private readonly SubmissionReport $submissionReport,
        private readonly Dashboard $dashboard,
    ) {}

    /**
     * Submissions list - filterable by user.
     */
    public function submissions(Request $request): View
    {
        $orgId = $this->getOrganizationId($request);

        if (! $orgId) {
            return $this->organizationPicker();
        }

        $organization = $this->tenant->getOrganization();
        $userId = $request->query('user_id');
        $state = $request->query('state');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        $submissions = $this->submissionReport
            ->search(SubmissionFilterData::fromQuery($userId, $state, $dateFrom, $dateTo), 25)
            ->withQueryString();

        $users = $this->membership->members($orgId);
        $stateCounts = $this->submissionReport->countByState();

        return view('portal.submissions', compact(
            'organization',
            'submissions',
            'users',
            'stateCounts',
            'userId',
            'state',
            'dateFrom',
            'dateTo'
        ));
    }

    /**
     * Certificate status and history.
     */
    public function certificates(Request $request): View
    {
        $orgId = $this->getOrganizationId($request);

        if (! $orgId) {
            return $this->organizationPicker();
        }

        $organization = $this->tenant->getOrganization();
        $activeCert = $this->dashboard->activeCertificate($orgId);

        return view('portal.certificates', compact('organization', 'activeCert'));
    }

    /**
     * User-specific activity log.
     */
    public function userActivity(Request $request, string $userId): View
    {
        $orgId = $this->getOrganizationId($request);

        if (! $orgId) {
            return $this->organizationPicker();
        }

        $organization = $this->tenant->getOrganization();

        // Membership of this organization is what makes the user visible here;
        // without it the id is just an unrelated account.
        $user = $this->membership->member($orgId, $userId);

        if (! $user) {
            abort(404, __('User not found'));
        }

        $submissions = $this->submissionReport->byUser($userId, 25)->withQueryString();
        $userStats = $this->submissionReport->userTotals($userId);

        return view('portal.user-activity', compact('organization', 'user', 'submissions', 'userStats'));
    }

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
     * Selection screen shown when no organization is resolved for this
     * session, as the dashboard shows it. It lists the user's own
     * organizations only.
     */
    private function organizationPicker(): View
    {
        return view('portal.select-org', [
            'organizations' => $this->membership->organizations(Auth::user()),
            'error' => null,
        ]);
    }
}
