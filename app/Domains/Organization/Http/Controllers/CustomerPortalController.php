<?php

declare(strict_types=1);

namespace App\Domains\Organization\Http\Controllers;

use App\Domains\Compliance\Fatoora\DTOs\SubmissionFilterData;
use App\Domains\Compliance\Fatoora\Services\CertificateService;
use App\Domains\Compliance\Fatoora\Services\CredentialStore;
use App\Domains\Compliance\Fatoora\Services\SubmissionReport;
use App\Domains\Invoice\Services\InvoiceReport;
use App\Domains\Organization\Http\Middleware\PortalTenant;
use App\Domains\Organization\Services\Membership;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Customer Portal Controller.
 *
 * Provides a tenant-scoped dashboard for customers like TaxFly.
 *
 * The organization is resolved by PortalTenant from the authenticated
 * session's active memberships. Nothing here may read a tenant identifier from
 * query, body or header — doing so reopens cross-tenant disclosure.
 *
 * PortalTenant puts the resolved tenant into TenantResolver, so the reports
 * called here are confined to it by BelongsToTenant's global scope rather than
 * by a filter each of them has to remember.
 */
class CustomerPortalController extends Controller
{
    public function __construct(
        private readonly CredentialStore $credentials,
        private readonly CertificateService $certificates,
        private readonly TenantResolver $tenant,
        private readonly Membership $membership,
        private readonly SubmissionReport $submissionReport,
        private readonly InvoiceReport $invoiceReport,
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
     * Customer dashboard - overview of their ZATCA compliance status.
     */
    public function dashboard(Request $request): View
    {
        $orgId = $this->getOrganizationId($request);

        if (! $orgId) {
            return $this->organizationPicker();
        }

        $organization = $this->tenant->getOrganization();

        if (! $organization) {
            return $this->organizationPicker('Organization not found');
        }

        $stats = [
            'invoices_today' => $this->invoiceReport->countSince(now()->startOfDay()),
            'invoices_month' => $this->invoiceReport->countSince(now()->startOfMonth()),
            ...Arr::only($this->submissionReport->summary(), ['cleared', 'reported', 'rejected', 'pending']),
        ];

        $certificate = $this->activeCertificate($orgId);
        $userActivity = $this->submissionReport->activityByUser(now()->subDays(7), 10);
        $recentSubmissions = $this->submissionReport->recent(10);

        return view('portal.dashboard', compact(
            'organization',
            'stats',
            'certificate',
            'userActivity',
            'recentSubmissions'
        ));
    }

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
        $activeCert = $this->activeCertificate($orgId);

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
            abort(404, 'User not found');
        }

        $submissions = $this->submissionReport->byUser($userId, 25)->withQueryString();
        $userStats = $this->submissionReport->userTotals($userId);

        return view('portal.user-activity', compact('organization', 'user', 'submissions', 'userStats'));
    }

    /**
     * The certificate this organization signs with, or null before onboarding.
     *
     * Read from the credential store, which is where onboarding writes. The
     * store holds the certificate an organization currently signs with and no
     * history of previous ones, so there is nothing further to show.
     */
    private function activeCertificate(?string $organizationId): ?object
    {
        if ($organizationId === null) {
            return null;
        }

        $details = $this->certificates->details(
            $this->credentials->certificate($organizationId)
        );

        return $details === null ? null : (object) $details;
    }
}
