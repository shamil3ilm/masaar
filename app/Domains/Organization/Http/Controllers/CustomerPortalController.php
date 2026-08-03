<?php

declare(strict_types=1);

namespace App\Domains\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Domains\Organization\Http\Middleware\PortalTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Customer Portal Controller.
 *
 * Provides a tenant-scoped dashboard for customers like TaxFly.
 *
 * The organization is resolved by PortalTenant from the authenticated
 * session's active memberships. Nothing here may read a tenant identifier from
 * query, body or header — doing so reopens cross-tenant disclosure.
 */
class CustomerPortalController extends Controller
{
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
     * Organizations offered on the selection screen — the user's own, only.
     */
    private function userOrganizations(): \Illuminate\Support\Collection
    {
        return Auth::user()
            ->activeOrganizations()
            ->orderBy('name')
            ->get(['organizations.id', 'organizations.name', 'organizations.vat_number', 'organizations.status']);
    }

    /**
     * Selection screen shown when no organization is resolved for this session.
     */
    private function organizationPicker(?string $error = null): View
    {
        return view('portal.select-org', [
            'organizations' => $this->userOrganizations(),
            'error' => $error,
        ]);
    }

    /**
     * Drop the current organization selection and offer the user's own list.
     */
    public function switchOrganization(Request $request): View
    {
        $request->session()->forget('portal_organization_id');

        return $this->organizationPicker();
    }

    /**
     * Customer dashboard - overview of their ZATCA compliance status.
     */
    public function dashboard(Request $request): View
    {
        $orgId = $this->getOrganizationId($request);

        if (!$orgId) {
            return $this->organizationPicker();
        }

        $organization = DB::table('organizations')->where('id', $orgId)->first();

        if (!$organization) {
            return $this->organizationPicker('Organization not found');
        }

        // Stats
        $stats = [
            'invoices_today' => DB::table('invoices')
                ->where('organization_id', $orgId)
                ->where('created_at', '>=', now()->startOfDay())
                ->count(),
            'invoices_month' => DB::table('invoices')
                ->where('organization_id', $orgId)
                ->where('created_at', '>=', now()->startOfMonth())
                ->count(),
            'cleared' => DB::table('invoice_submissions')
                ->where('organization_id', $orgId)
                ->where('state', 'cleared')
                ->count(),
            'reported' => DB::table('invoice_submissions')
                ->where('organization_id', $orgId)
                ->where('state', 'reported')
                ->count(),
            'rejected' => DB::table('invoice_submissions')
                ->where('organization_id', $orgId)
                ->where('state', 'rejected')
                ->count(),
            'pending' => DB::table('invoice_submissions')
                ->where('organization_id', $orgId)
                ->whereIn('state', ['pending', 'queued', 'submitted'])
                ->count(),
        ];

        // Certificate info
        $certificate = DB::table('certificate_lineage')
            ->where('organization_id', $orgId)
            ->where('status', 'active')
            ->first();

        // Recent activity by user
        $userActivity = DB::table('invoice_submissions as s')
            ->leftJoin('users as u', 's.created_by', '=', 'u.id')
            ->where('s.organization_id', $orgId)
            ->where('s.created_at', '>=', now()->subDays(7))
            ->selectRaw('COALESCE(u.name, u.email, "System") as user_name, u.id as user_id, COUNT(*) as submission_count')
            ->groupBy('u.id', 'u.name', 'u.email')
            ->orderByDesc('submission_count')
            ->limit(10)
            ->get();

        // Recent submissions
        $recentSubmissions = DB::table('invoice_submissions')
            ->where('organization_id', $orgId)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

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

        if (!$orgId) {
            return $this->organizationPicker();
        }

        $organization = DB::table('organizations')->where('id', $orgId)->first();
        $userId = $request->query('user_id');
        $state = $request->query('state');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        $query = DB::table('invoice_submissions as s')
            ->leftJoin('users as u', 's.created_by', '=', 'u.id')
            ->leftJoin('invoices as i', 's.invoice_id', '=', 'i.id')
            ->where('s.organization_id', $orgId)
            ->select([
                's.*',
                'u.name as user_name',
                'u.email as user_email',
                'i.invoice_number',
                'i.total as invoice_total',
            ])
            ->orderByDesc('s.created_at');

        if ($userId) {
            $query->where('s.created_by', $userId);
        }

        if ($state) {
            $query->where('s.state', $state);
        }

        if ($dateFrom) {
            $query->where('s.created_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->where('s.created_at', '<=', $dateTo . ' 23:59:59');
        }

        $submissions = $query->paginate(25);

        // Get users for filter dropdown (via organization_user pivot)
        $users = DB::table('users')
            ->join('organization_user', 'users.id', '=', 'organization_user.user_id')
            ->where('organization_user.organization_id', $orgId)
            ->where('organization_user.status', 'active')
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.email']);

        // State counts
        $stateCounts = DB::table('invoice_submissions')
            ->where('organization_id', $orgId)
            ->selectRaw('state, COUNT(*) as count')
            ->groupBy('state')
            ->pluck('count', 'state');

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

        if (!$orgId) {
            return $this->organizationPicker();
        }

        $organization = DB::table('organizations')->where('id', $orgId)->first();

        $activeCert = DB::table('certificate_lineage')
            ->where('organization_id', $orgId)
            ->where('status', 'active')
            ->first();

        $certHistory = DB::table('certificate_lineage')
            ->where('organization_id', $orgId)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return view('portal.certificates', compact('organization', 'activeCert', 'certHistory'));
    }

    /**
     * User-specific activity log.
     */
    public function userActivity(Request $request, string $userId): View
    {
        $orgId = $this->getOrganizationId($request);

        if (!$orgId) {
            return $this->organizationPicker();
        }

        $organization = DB::table('organizations')->where('id', $orgId)->first();

        $user = DB::table('users')
            ->join('organization_user', 'users.id', '=', 'organization_user.user_id')
            ->where('users.id', $userId)
            ->where('organization_user.organization_id', $orgId)
            ->select('users.*')
            ->first();

        if (!$user) {
            abort(404, 'User not found');
        }

        // User's submissions
        $submissions = DB::table('invoice_submissions as s')
            ->leftJoin('invoices as i', 's.invoice_id', '=', 'i.id')
            ->where('s.organization_id', $orgId)
            ->where('s.created_by', $userId)
            ->select([
                's.*',
                'i.invoice_number',
                'i.total as invoice_total',
            ])
            ->orderByDesc('s.created_at')
            ->paginate(25);

        // User stats
        $userStats = [
            'total' => DB::table('invoice_submissions')
                ->where('organization_id', $orgId)
                ->where('created_by', $userId)
                ->count(),
            'cleared' => DB::table('invoice_submissions')
                ->where('organization_id', $orgId)
                ->where('created_by', $userId)
                ->where('state', 'cleared')
                ->count(),
            'rejected' => DB::table('invoice_submissions')
                ->where('organization_id', $orgId)
                ->where('created_by', $userId)
                ->where('state', 'rejected')
                ->count(),
            'today' => DB::table('invoice_submissions')
                ->where('organization_id', $orgId)
                ->where('created_by', $userId)
                ->where('created_at', '>=', now()->startOfDay())
                ->count(),
        ];

        return view('portal.user-activity', compact('organization', 'user', 'submissions', 'userStats'));
    }
}
