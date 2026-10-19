<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers;

use App\Domains\Compliance\Countries\SaudiArabia\Reports\Dashboard;
use App\Domains\Compliance\Countries\SaudiArabia\Reports\SubmissionHistory;
use App\Domains\Compliance\Countries\SaudiArabia\Reports\SubmissionReport;
use App\Domains\Organization\Http\Middleware\PortalTenant;
use App\Domains\Organization\Services\Membership;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use App\Http\Listing\Lists;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        private readonly SubmissionHistory $history,
        private readonly Lists $lists,
        private readonly Dashboard $dashboard,
    ) {}

    /**
     * Submissions, sorted and narrowed as the query string asks
     * (SubmissionHistory).
     */
    public function submissions(Request $request): View
    {
        $orgId = $this->getOrganizationId($request);

        if (! $orgId) {
            return $this->organizationPicker();
        }

        $list = $this->lists->portal($this->history->spec($orgId, 'portal.submissions'), $request, $orgId, 'portal.submissions.export');

        return view('countries.saudi-arabia.submissions', [
            'organization' => $this->tenant->getOrganization(),
            'submissions' => $this->history->page($orgId, $list),
            'stateCounts' => $this->submissionReport->countByState(),
            'list' => $list,
            // The people it can be narrowed to, and what it is narrowed by,
            // under the names the page has always given them.
            'users' => $this->membership->members($orgId),
            'state' => $list->value('state'),
            'userId' => $list->value('user_id'),
            'dateFrom' => $list->input('date_from') ?: null,
            'dateTo' => $list->input('date_to') ?: null,
        ]);
    }

    /** Exactly the submissions the list's filters give, as a CSV. */
    public function export(Request $request): StreamedResponse
    {
        $orgId = $this->getOrganizationId($request) ?? abort(404);
        $list = $this->lists->portal($this->history->spec($orgId, 'portal.submissions'), $request, $orgId);

        return $this->history->csv($orgId, $list, 'submissions-'.now()->format('Y-m-d').'.csv');
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

        return view('countries.saudi-arabia.certificate-status', compact('organization', 'activeCert'));
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
