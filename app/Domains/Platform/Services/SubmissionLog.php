<?php

declare(strict_types=1);

namespace App\Domains\Platform\Services;

use App\Domains\Platform\DTOs\FilterData;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Submissions across every tenant, for troubleshooting from the console.
 *
 * Cross-tenant by design, so it reads through the query builder rather than
 * the tenant-scoped model.
 */
class SubmissionLog
{
    /**
     * Columns the admin API exposes for a submission.
     */
    private const API_COLUMNS = [
        'id',
        'invoice_id',
        'org_id',
        'state',
        'submission_type',
        'clearance_status',
        'reporting_status',
        'last_error_code',
        'last_error',
        'retry_count',
        'created_at',
        'completed_at',
    ];

    /**
     * The latest submissions matching a filter, limited to API_COLUMNS.
     */
    public function latest(FilterData $filter, int $limit): Collection
    {
        return DB::table('invoice_submissions')
            ->when($filter->state !== null, fn (Builder $q) => $q->where('state', $filter->state))
            ->when($filter->organizationId !== null, fn (Builder $q) => $q->where('org_id', $filter->organizationId))
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(self::API_COLUMNS);
    }

    /**
     * Submissions matching a filter, newest first, with their organization's
     * name.
     */
    public function page(FilterData $filter, int $perPage): LengthAwarePaginator
    {
        return DB::table('invoice_submissions')
            ->leftJoin('organizations', 'invoice_submissions.org_id', '=', 'organizations.id')
            ->select([
                'invoice_submissions.*',
                'organizations.name as organization_name',
            ])
            ->when($filter->state !== null, fn (Builder $q) => $q->where('invoice_submissions.state', $filter->state))
            ->when($filter->organizationId !== null, fn (Builder $q) => $q->where('invoice_submissions.org_id', $filter->organizationId))
            ->orderByDesc('invoice_submissions.created_at')
            ->paginate($perPage);
    }

    /**
     * Number of submissions in each state, keyed by state.
     *
     * @return Collection<string, int|string>
     */
    public function countByState(): Collection
    {
        return DB::table('invoice_submissions')
            ->selectRaw('state, COUNT(*) as count')
            ->groupBy('state')
            ->pluck('count', 'state');
    }

    /**
     * Rejections per hour over the last given hours, oldest hour first.
     *
     * Hours with no submissions are absent.
     *
     * @return Collection<int, array{hour: string, total: int|string, rejected: int|string, successful: int|string, error_rate: float|int}>
     */
    public function errorRates(int $hours): Collection
    {
        return DB::table('invoice_submissions')
            ->where('created_at', '>=', now()->subHours($hours))
            ->selectRaw("
                DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') as hour,
                COUNT(*) as total,
                SUM(CASE WHEN state = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN state IN ('cleared', 'reported') THEN 1 ELSE 0 END) as successful
            ")
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->map(fn (object $row) => [
                'hour' => $row->hour,
                'total' => $row->total,
                'rejected' => $row->rejected,
                'successful' => $row->successful,
                'error_rate' => $row->total > 0 ? round(($row->rejected / $row->total) * 100, 2) : 0,
            ]);
    }

    /**
     * Rejections recorded at or after a moment.
     */
    public function rejectionsSince(DateTimeInterface $since): int
    {
        return DB::table('invoice_submissions')
            ->where('state', 'rejected')
            ->where('created_at', '>=', $since)
            ->count();
    }
}
