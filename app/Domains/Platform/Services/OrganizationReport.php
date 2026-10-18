<?php

declare(strict_types=1);

namespace App\Domains\Platform\Services;

use App\Domains\Compliance\Countries\SaudiArabia\Signing\CertificateService;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Compliance\Engine\Services\JurisdictionRegistry;
use App\Domains\Organization\Models\Organization;
use App\Http\Listing\CsvExport;
use App\Http\Listing\Filters\Choice;
use App\Http\Listing\Filters\DateRange;
use App\Http\Listing\Filters\Search;
use App\Http\Listing\ListSpec;
use App\Http\Listing\ListState;
use App\Http\Listing\Sort;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Organizations as the platform console sees them: every tenant, with what
 * each has filed.
 *
 * Cross-tenant by design, so it reads through the query builder rather than
 * the tenant-scoped models.
 */
class OrganizationReport
{
    public function __construct(
        private readonly CredentialStore $credentials,
        private readonly CertificateService $certificates,
        private readonly JurisdictionRegistry $jurisdictions,
    ) {}

    /**
     * What the organizations list sorts and narrows by.
     */
    public function spec(string $key): ListSpec
    {
        $countries = [];

        foreach ($this->jurisdictions->registered() as $code) {
            $countries[$code] = $this->jurisdictions->get($code)->name();
        }

        return new ListSpec(
            key: $key,
            sorts: [
                'newest' => new Sort('Newest', 'organizations.created_at'),
                'name' => new Sort('Name', 'organizations.name', 'asc'),
            ],
            defaultSort: 'newest',
            filters: [
                new Search('q', 'Search', ['organizations.name', 'organizations.vat_number'], 'Name or VAT number'),
                new Choice('status', 'Status', [
                    Organization::STATUS_ACTIVE => 'Active',
                    Organization::STATUS_PENDING_REVIEW => 'Awaiting review',
                    Organization::STATUS_REJECTED => 'Rejected',
                    Organization::STATUS_SUSPENDED => 'Suspended',
                ], 'organizations.status'),
                new Choice('country', 'Country', $countries, 'organizations.country'),
                new DateRange('organizations.created_at', 'Registered', instant: true),
            ],
            defaultPerPage: 20,
            tiebreaker: 'organizations.id',
        );
    }

    /**
     * Organizations as the list asks, each with the expiry of the
     * certificate it signs with.
     *
     * Expiry is read from the certificate the platform holds, which costs one
     * decryption per row: acceptable on a paginated admin screen, not
     * somewhere it would run per invoice.
     */
    public function history(ListState $list): LengthAwarePaginator
    {
        $organizations = $list->paginate($this->query());

        $organizations->setCollection(
            $organizations->getCollection()->map(fn (object $organization) => (object) [
                ...(array) $organization,
                'cert_expires_at' => $this->certificateDetails((string) $organization->id)['valid_to'] ?? null,
            ])
        );

        return $organizations;
    }

    /** Every organization the list's filters give, as a CSV. */
    public function csv(ListState $list, string $fileName): StreamedResponse
    {
        return CsvExport::download($fileName, [
            'Name' => fn (object $row): string => (string) $row->name,
            'VAT number' => fn (object $row): string => (string) $row->vat_number,
            'Country' => fn (object $row): string => (string) $row->country,
            'Status' => fn (object $row): string => (string) $row->status,
            'Registered' => fn (object $row): string => (string) $row->created_at,
            'Id' => fn (object $row): string => (string) $row->id,
        ], $list->apply($this->query()->addSelect('organizations.country'))->lazy(500));
    }

    private function query(): Builder
    {
        return DB::table('organizations')->select([
            'organizations.id',
            'organizations.name',
            'organizations.vat_number',
            'organizations.status',
            'organizations.created_at',
        ]);
    }

    /**
     * Accepted submissions (cleared or reported) per organization, keyed by
     * organization id. An organization with no submissions is absent.
     *
     * @param  Collection<int, string>  $organizationIds
     * @return Collection<string, int|string>
     */
    public function acceptedSubmissions(Collection $organizationIds): Collection
    {
        return DB::table('invoice_submissions')
            ->whereIn('org_id', $organizationIds)
            ->selectRaw('org_id, COUNT(*) as total, SUM(CASE WHEN state IN ("cleared", "reported") THEN 1 ELSE 0 END) as successful')
            ->groupBy('org_id')
            ->pluck('successful', 'org_id');
    }

    /**
     * One organization's row, or null when there is none.
     */
    public function find(string $id): ?object
    {
        return DB::table('organizations')->where('id', $id)->first();
    }

    /**
     * What one organization has filed.
     *
     * @return array{invoices: int, submissions: int, cleared: int, rejected: int}
     */
    public function stats(string $id): array
    {
        $invoices = DB::table('invoices')->where('org_id', $id)->count();

        // One query for all the submission counts.
        $submissions = DB::table('invoice_submissions')
            ->where('org_id', $id)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN state = 'cleared' THEN 1 ELSE 0 END) as cleared,
                SUM(CASE WHEN state = 'rejected' THEN 1 ELSE 0 END) as rejected
            ")
            ->first();

        return [
            'invoices' => $invoices,
            'submissions' => (int) ($submissions->total ?? 0),
            'cleared' => (int) ($submissions->cleared ?? 0),
            'rejected' => (int) ($submissions->rejected ?? 0),
        ];
    }

    /**
     * One organization's latest submissions, every column.
     */
    public function recentSubmissions(string $id, int $limit): Collection
    {
        return DB::table('invoice_submissions')
            ->where('org_id', $id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Who belongs to an organization, with their role and membership status.
     * Named columns only: the users table holds a password hash.
     */
    public function members(string $id): Collection
    {
        return DB::table('organization_user')
            ->join('users', 'users.id', '=', 'organization_user.user_id')
            ->where('organization_user.org_id', $id)
            ->orderBy('users.name')
            ->get([
                'users.id',
                'users.name',
                'users.email',
                'users.status as account_status',
                'organization_user.role',
                'organization_user.status',
                'organization_user.created_at as joined_at',
            ]);
    }

    /**
     * The certificate an organization signs with, or null before onboarding.
     */
    public function certificate(string $id): ?object
    {
        $details = $this->certificateDetails($id);

        return $details === null ? null : (object) $details;
    }

    /**
     * Organizations with the most invoices, with how many and their sum.
     */
    public function topByInvoices(int $limit): Collection
    {
        return DB::table('invoices')
            ->join('organizations', 'invoices.org_id', '=', 'organizations.id')
            ->selectRaw('organizations.id, organizations.name, COUNT(invoices.id) as invoice_count, SUM(invoices.total) as total_amount')
            ->groupBy('organizations.id', 'organizations.name')
            ->orderByDesc('invoice_count')
            ->limit($limit)
            ->get();
    }

    /**
     * @return array{serial_number: ?string, valid_from: string, valid_to: string, status: string}|null
     */
    private function certificateDetails(string $id): ?array
    {
        return $this->certificates->details($this->credentials->certificate($id));
    }
}
