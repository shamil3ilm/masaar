<?php

declare(strict_types=1);

namespace App\Domains\Platform\Services;

use App\Domains\Compliance\Fatoora\Services\OfflineQueue;
use App\Domains\Organization\Services\TenantResolver;
use App\Domains\Platform\DTOs\FilterData;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
     * Items matching a filter, newest first, with their organization's name.
     */
    public function page(FilterData $filter, int $perPage): LengthAwarePaginator
    {
        return DB::table('offline_queue')
            ->leftJoin('organizations', 'offline_queue.org_id', '=', 'organizations.id')
            ->select([
                'offline_queue.*',
                'organizations.name as organization_name',
            ])
            ->when($filter->state !== null, fn (Builder $q) => $q->where('offline_queue.state', $filter->state))
            ->when($filter->organizationId !== null, fn (Builder $q) => $q->where('offline_queue.org_id', $filter->organizationId))
            ->orderByDesc('offline_queue.queued_at')
            ->paginate($perPage);
    }

    /**
     * Number of items in each state, keyed by state.
     *
     * @return Collection<string, int|string>
     */
    public function countByState(): Collection
    {
        return DB::table('offline_queue')
            ->selectRaw('state, COUNT(*) as count')
            ->groupBy('state')
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
