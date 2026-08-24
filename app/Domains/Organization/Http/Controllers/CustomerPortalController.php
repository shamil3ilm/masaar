<?php

declare(strict_types=1);

namespace App\Domains\Organization\Http\Controllers;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Fatoora\Models\InvoiceSubmission;
use App\Domains\Compliance\Fatoora\Services\CertificateService;
use App\Domains\Compliance\Fatoora\Services\CredentialStore;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Http\Middleware\PortalTenant;
use App\Domains\Organization\Models\Organization;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
 * The queries below carry no org_id condition on purpose. PortalTenant puts
 * the resolved tenant into TenantResolver, so BelongsToTenant's global scope
 * applies it to every model here. Adding the filter by hand would work today
 * and hide the omission on the day someone forgets.
 */
class CustomerPortalController extends Controller
{
    public function __construct(
        private readonly CredentialStore $credentials,
        private readonly CertificateService $certificates,
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
     * Organizations offered on the selection screen — the user's own, only.
     */
    private function userOrganizations(): Collection
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

        $organization = Organization::find($orgId);

        if (! $organization) {
            return $this->organizationPicker('Organization not found');
        }

        // One grouped query rather than one COUNT per state.
        $byState = InvoiceSubmission::query()
            ->selectRaw('state, COUNT(*) as total')
            ->groupBy('state')
            ->pluck('total', 'state');

        $stats = [
            'invoices_today' => Invoice::where('created_at', '>=', now()->startOfDay())->count(),
            'invoices_month' => Invoice::where('created_at', '>=', now()->startOfMonth())->count(),
            'cleared' => (int) $byState->get('cleared', 0),
            'reported' => (int) $byState->get('reported', 0),
            'rejected' => (int) $byState->get('rejected', 0),
            'pending' => (int) $byState->only(['pending', 'queued', 'submitted'])->sum(),
        ];

        $certificate = $this->activeCertificate($orgId);

        $userActivity = InvoiceSubmission::query()
            ->leftJoin('users', 'invoice_submissions.created_by', '=', 'users.id')
            ->where('invoice_submissions.created_at', '>=', now()->subDays(7))
            ->selectRaw('COALESCE(users.name, users.email, ?) as user_name, users.id as user_id, COUNT(*) as submission_count', ['System'])
            ->groupBy('users.id', 'users.name', 'users.email')
            ->orderByDesc('submission_count')
            ->limit(10)
            ->get();

        $recentSubmissions = InvoiceSubmission::query()
            ->latest()
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

        if (! $orgId) {
            return $this->organizationPicker();
        }

        $organization = Organization::find($orgId);
        $userId = $request->query('user_id');
        $state = $request->query('state');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        $submissions = InvoiceSubmission::query()
            ->leftJoin('users', 'invoice_submissions.created_by', '=', 'users.id')
            ->leftJoin('invoices', 'invoice_submissions.invoice_id', '=', 'invoices.id')
            ->select([
                'invoice_submissions.*',
                'users.name as user_name',
                'users.email as user_email',
                'invoices.invoice_number',
                'invoices.total as invoice_total',
            ])
            ->when($userId, fn ($q) => $q->where('invoice_submissions.created_by', $userId))
            ->when($state, fn ($q) => $q->where('invoice_submissions.state', $state))
            ->when($dateFrom, fn ($q) => $q->where('invoice_submissions.created_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->where('invoice_submissions.created_at', '<=', $dateTo.' 23:59:59'))
            ->orderByDesc('invoice_submissions.created_at')
            ->paginate(25)
            ->withQueryString();

        $users = $this->organizationUsers($orgId);

        $stateCounts = InvoiceSubmission::query()
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

        if (! $orgId) {
            return $this->organizationPicker();
        }

        $organization = Organization::find($orgId);
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

        $organization = Organization::find($orgId);

        // Membership of this organization is what makes the user visible here;
        // without it the id is just an unrelated account.
        $user = $this->organizationUsers($orgId)->firstWhere('id', $userId);

        if (! $user) {
            abort(404, 'User not found');
        }

        $submissions = InvoiceSubmission::query()
            ->leftJoin('invoices', 'invoice_submissions.invoice_id', '=', 'invoices.id')
            ->where('invoice_submissions.created_by', $userId)
            ->select([
                'invoice_submissions.*',
                'invoices.invoice_number',
                'invoices.total as invoice_total',
            ])
            ->orderByDesc('invoice_submissions.created_at')
            ->paginate(25)
            ->withQueryString();

        $byState = InvoiceSubmission::query()
            ->where('created_by', $userId)
            ->selectRaw('state, COUNT(*) as total')
            ->groupBy('state')
            ->pluck('total', 'state');

        $userStats = [
            'total' => (int) $byState->sum(),
            'cleared' => (int) $byState->get('cleared', 0),
            'rejected' => (int) $byState->get('rejected', 0),
            'today' => InvoiceSubmission::where('created_by', $userId)
                ->where('created_at', '>=', now()->startOfDay())
                ->count(),
        ];

        return view('portal.user-activity', compact('organization', 'user', 'submissions', 'userStats'));
    }

    /**
     * Members of one organization.
     *
     * Users are not tenant-scoped — a person can belong to several
     * organizations — so this walks the membership pivot explicitly.
     *
     * @return Collection<int, User>
     */
    private function organizationUsers(string $orgId): Collection
    {
        return User::query()
            ->whereHas('activeOrganizations', fn ($q) => $q->whereKey($orgId))
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
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
