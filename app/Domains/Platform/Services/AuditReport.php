<?php

declare(strict_types=1);

namespace App\Domains\Platform\Services;

use App\Http\Listing\CsvExport;
use App\Http\Listing\Filters\Choice;
use App\Http\Listing\Filters\DateRange;
use App\Http\Listing\Filters\Search;
use App\Http\Listing\ListSpec;
use App\Http\Listing\ListState;
use App\Http\Listing\Sort;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The audit trail, for reading.
 *
 * Every tenant's and every admin's entries in one place, filtered by what
 * happened, who did it and when. Cross-tenant by design.
 */
class AuditReport
{
    /**
     * The groups an operator filters by. Each is an action prefix.
     */
    public const CATEGORIES = [
        'admin.' => 'Admin actions',
        'security.' => 'Sign-ins and security',
        'auth.' => 'Registrations and API sign-outs',
    ];

    /**
     * What the audit list narrows by: the kind of entry, who acted, and when.
     * Newest first, always - the order an incident is read in.
     */
    public function spec(string $key): ListSpec
    {
        return new ListSpec(
            key: $key,
            sorts: ['newest' => new Sort('Newest', 'audit_logs.created_at')],
            defaultSort: 'newest',
            filters: [
                new Choice('category', 'Category', self::CATEGORIES, fn (QueryBuilder $query, string $prefix) => $query->where('audit_logs.action', 'like', $prefix.'%'), 'Everything'),
                new Search('actor', 'Who', ['actors.email'], 'Email of the person who acted'),
                new Search('organization', 'Organization', ['organizations.name']),
                new Search('action', 'Action', ['audit_logs.action'], 'For example member.invited'),
                new DateRange('audit_logs.created_at', 'When', instant: true),
            ],
            defaultPerPage: 50,
            tiebreaker: 'audit_logs.id',
        );
    }

    public function page(ListState $list): LengthAwarePaginator
    {
        $page = $list->paginate($this->query());

        $page->setCollection($page->getCollection()->map(fn (object $row): object => $this->decoded($row)));

        return $page;
    }

    /** Every entry the list's filters give, as a CSV. */
    public function csv(ListState $list, string $fileName): StreamedResponse
    {
        return CsvExport::download($fileName, [
            'When' => fn (object $row): string => (string) $row->created_at,
            'Who' => fn (object $row): string => (string) $row->actor_email,
            'Organization' => fn (object $row): string => (string) $row->organization,
            'Action' => fn (object $row): string => (string) $row->action,
            'Record' => fn (object $row): string => trim($row->entity_type.' '.$row->entity_id),
            'Address' => fn (object $row): string => (string) $row->ip_address,
            'Details' => fn (object $row): string => (string) $row->metadata,
        ], $list->apply($this->query())->lazy(500));
    }

    private function query(): Builder
    {
        return DB::table('audit_logs')
            ->leftJoin('users as actors', 'actors.id', '=', 'audit_logs.user_id')
            ->leftJoin('organizations', 'organizations.id', '=', 'audit_logs.org_id')
            ->select([
                'audit_logs.id',
                'audit_logs.action',
                'audit_logs.entity_type',
                'audit_logs.entity_id',
                'audit_logs.old_values',
                'audit_logs.new_values',
                'audit_logs.metadata',
                'audit_logs.ip_address',
                'audit_logs.created_at',
                'actors.id as actor_id',
                'actors.email as actor_email',
                'organizations.name as organization',
            ]);
    }

    private function decoded(object $row): object
    {
        return (object) [
            ...(array) $row,
            'metadata' => $this->json($row->metadata),
            'old_values' => $this->json($row->old_values),
            'new_values' => $this->json($row->new_values),
        ];
    }

    /**
     * @return array<mixed>
     */
    private function json(mixed $value): array
    {
        return is_string($value) ? (array) json_decode($value, true) : [];
    }
}
