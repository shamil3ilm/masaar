<?php

declare(strict_types=1);

namespace App\Domains\Platform\Services;

use App\Domains\Compliance\Countries\SaudiArabia\Filing\OfflineQueue;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Listing\CsvExport;
use App\Http\Listing\Filters\Choice;
use App\Http\Listing\Filters\DateRange;
use App\Http\Listing\Filters\Equals;
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
 * The offline submission queue across every tenant.
 *
 * Cross-tenant by design, so it reads through the query builder rather than
 * the tenant-scoped model.
 */
class QueueReport
{
    /**
     * States of an item that still needs attention.
     */
    private const OPEN_STATES = ['pending', 'processing', 'failed'];

    /** Waiting for the drain, and given up on until someone retries it. */
    public const PENDING = OfflineQueue::STATE_PENDING;

    public const FAILED = OfflineQueue::STATE_FAILED;

    public function __construct(
        private readonly OfflineQueue $offlineQueue,
        private readonly TenantResolver $tenants,
    ) {}

    /**
     * Items per state, how many organizations each touches, the oldest wait
     * and the latest failures.
     *
     * @return array{summary: array<string, int>, organizations_affected: array<string, int>, oldest_pending_at: ?string, recent_failures: Collection}
     */
    public function summary(): array
    {
        $stats = DB::table('offline_queue')
            ->selectRaw('
                state,
                COUNT(*) as count,
                COUNT(DISTINCT org_id) as organizations
            ')
            ->groupBy('state')
            ->get()
            ->keyBy('state');

        return [
            'summary' => [
                'pending' => $stats->get('pending')?->count ?? 0,
                'processing' => $stats->get('processing')?->count ?? 0,
                'completed' => $stats->get('completed')?->count ?? 0,
                'failed' => $stats->get('failed')?->count ?? 0,
            ],
            'organizations_affected' => [
                'pending' => $stats->get('pending')?->organizations ?? 0,
                'failed' => $stats->get('failed')?->organizations ?? 0,
            ],
            'oldest_pending_at' => $this->oldestPendingAt(),
            'recent_failures' => $this->recentFailures(10),
        ];
    }

    /**
     * One organization's queue status, as OfflineQueue reports it.
     *
     * OfflineQueue counts through the tenant-scoped model and the console has
     * no tenant of its own, which scoped every count to nothing. Acting as
     * the organization asked about counts that organization's items.
     */
    public function statusOf(string $organizationId): array
    {
        return $this->tenants->runAs(
            $organizationId,
            fn () => $this->offlineQueue->getStatus($organizationId)
        );
    }

    /**
     * One organization's items that still need attention, newest first.
     */
    public function openItems(string $organizationId, int $limit): Collection
    {
        return DB::table('offline_queue')
            ->where('org_id', $organizationId)
            ->whereIn('state', self::OPEN_STATES)
            ->orderByDesc('queued_at')
            ->limit($limit)
            ->get();
    }

    /**
     * What the queue list sorts and narrows by.
     */
    public function spec(string $key): ListSpec
    {
        return new ListSpec(
            key: $key,
            sorts: [
                'newest' => new Sort('Queued', 'offline_queue.queued_at'),
                'attempts' => new Sort('Attempts', 'offline_queue.attempts'),
            ],
            defaultSort: 'newest',
            filters: [
                new Choice('state', 'State', ['pending' => 'Pending', 'processing' => 'Processing', 'completed' => 'Completed', 'failed' => 'Failed'], 'offline_queue.state', 'All States'),
                new Search('organization', 'Organization', ['organizations.name'], 'Organization name'),
                new Equals('org_id', 'Organization ID', 'offline_queue.org_id'),
                new DateRange('offline_queue.queued_at', 'Queued', instant: true),
            ],
            defaultPerPage: 50,
            tiebreaker: 'offline_queue.id',
        );
    }

    /**
     * Queue items with their organization's name, as the list asks.
     */
    public function history(ListState $list): LengthAwarePaginator
    {
        return $list->paginate($this->query());
    }

    /** Every queue item the list's filters give, as a CSV. */
    public function csv(ListState $list, string $fileName): StreamedResponse
    {
        return CsvExport::download($fileName, [
            'Invoice' => fn (object $row): string => (string) $row->invoice_id,
            'Organization' => fn (object $row): string => (string) $row->organization_name,
            'State' => fn (object $row): string => (string) $row->state,
            'Attempts' => fn (object $row): string => $row->attempts.'/'.$row->max_attempts,
            'Queued' => fn (object $row): string => (string) $row->queued_at,
            'Error' => fn (object $row): string => (string) $row->last_error,
        ], $list->apply($this->query())->lazy(500));
    }

    private function query(): Builder
    {
        return DB::table('offline_queue')
            ->leftJoin('organizations', 'offline_queue.org_id', '=', 'organizations.id')
            ->select([
                'offline_queue.*',
                'organizations.name as organization_name',
            ]);
    }

    /**
     * Number of items in each state, keyed by state.
     *
     * @return Collection<string, int|string>
     */
    public function countByState(): Collection
    {
        // Ordered because the view renders these as they come: without it the
        // states arrive in whatever order the driver returns them, which is
        // not the same on MySQL as on SQLite and need not be the same twice.
        return DB::table('offline_queue')
            ->selectRaw('state, COUNT(*) as count')
            ->groupBy('state')
            ->orderBy('state')
            ->pluck('count', 'state');
    }

    /**
     * Number of items in one state.
     */
    public function countInState(string $state): int
    {
        return DB::table('offline_queue')
            ->where('state', $state)
            ->count();
    }

    private function oldestPendingAt(): ?string
    {
        return DB::table('offline_queue')
            ->where('state', 'pending')
            ->orderBy('queued_at')
            ->value('queued_at');
    }

    private function recentFailures(int $limit): Collection
    {
        return DB::table('offline_queue')
            ->where('state', 'failed')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get(['id', 'invoice_id', 'org_id', 'last_error', 'attempts', 'updated_at']);
    }
}
