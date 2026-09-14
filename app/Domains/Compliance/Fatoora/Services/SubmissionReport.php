<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Domains\Compliance\Fatoora\DTOs\SubmissionFilterData;
use App\Domains\Compliance\Fatoora\Models\InvoiceSubmission;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Counts and lists of the current tenant's submissions.
 *
 * No query here carries an org_id condition, on purpose. InvoiceSubmission
 * uses BelongsToTenant, so the global scope applies whatever tenant the
 * request resolved. Filtering by hand as well would work today and hide the
 * omission on the day someone forgets. A request with no tenant context gets
 * nothing back.
 */
class SubmissionReport
{
    /**
     * States of a submission that has not reached the authority's verdict.
     *
     * The column allows no plain "pending"; a submission waiting to be sent is
     * pending_submission.
     */
    private const IN_FLIGHT = ['pending_submission', 'queued', 'submitted'];

    /**
     * Number of submissions in each state, keyed by state.
     *
     * @return Collection<string, int|string>
     */
    public function countByState(): Collection
    {
        return $this->groupByState(InvoiceSubmission::query());
    }

    /**
     * Number of one user's submissions in each state, keyed by state.
     *
     * @return Collection<string, int|string>
     */
    public function countByStateFor(string $userId): Collection
    {
        return $this->groupByState(InvoiceSubmission::where('created_by', $userId));
    }

    /**
     * Totals by outcome, and the share of verdicts that were accepted.
     *
     * With no verdict yet the success rate is 100.
     *
     * @return array{total: int, cleared: int, reported: int, rejected: int, pending: int, success_rate: float}
     */
    public function summary(): array
    {
        $byState = $this->countByState();

        $cleared = (int) $byState->get('cleared', 0);
        $reported = (int) $byState->get('reported', 0);
        $rejected = (int) $byState->get('rejected', 0);
        $settled = $cleared + $reported + $rejected;

        return [
            'total' => (int) $byState->sum(),
            'cleared' => $cleared,
            'reported' => $reported,
            'rejected' => $rejected,
            'pending' => (int) $byState->only(self::IN_FLIGHT)->sum(),
            'success_rate' => $settled > 0 ? round((($cleared + $reported) / $settled) * 100, 2) : 100.0,
        ];
    }

    /**
     * One user's submission figures.
     *
     * @return array{total: int, cleared: int, rejected: int, today: int}
     */
    public function userTotals(string $userId): array
    {
        $byState = $this->countByStateFor($userId);

        return [
            'total' => (int) $byState->sum(),
            'cleared' => (int) $byState->get('cleared', 0),
            'rejected' => (int) $byState->get('rejected', 0),
            'today' => InvoiceSubmission::where('created_by', $userId)
                ->where('created_at', '>=', now()->startOfDay())
                ->count(),
        ];
    }

    /**
     * Submissions created per day since a moment, keyed by Y-m-d.
     *
     * Days with none are absent.
     *
     * @return array<string, int>
     */
    public function dailyCounts(DateTimeInterface $since): array
    {
        return InvoiceSubmission::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('count', 'date')
            ->toArray();
    }

    /**
     * Submissions per author since a moment, busiest first.
     *
     * Submissions with no author are grouped under "System".
     */
    public function activityByUser(DateTimeInterface $since, int $limit): Collection
    {
        return InvoiceSubmission::query()
            ->leftJoin('users', 'invoice_submissions.created_by', '=', 'users.id')
            ->where('invoice_submissions.created_at', '>=', $since)
            ->selectRaw('COALESCE(users.name, users.email, ?) as user_name, users.id as user_id, COUNT(*) as submission_count', ['System'])
            ->groupBy('users.id', 'users.name', 'users.email')
            ->orderByDesc('submission_count')
            ->limit($limit)
            ->get();
    }

    /**
     * The latest submissions.
     */
    public function recent(int $limit): Collection
    {
        return InvoiceSubmission::query()
            ->latest()
            ->limit($limit)
            ->get();
    }

    /**
     * The latest submissions as a feed: id, invoice, status and when it last
     * changed.
     *
     * @return Collection<int, array{id: string, invoice_id: string, status: string, timestamp: mixed}>
     */
    public function activity(int $limit): Collection
    {
        return InvoiceSubmission::query()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->select(['id', 'invoice_id', 'state', 'reporting_status', 'created_at', 'updated_at'])
            ->get()
            ->map(fn (InvoiceSubmission $submission) => [
                'id' => $submission->id,
                'invoice_id' => $submission->invoice_id,
                'status' => $submission->state ?? $submission->reporting_status ?? 'pending',
                'timestamp' => $submission->updated_at ?? $submission->created_at,
            ]);
    }

    /**
     * Submissions with their author and invoice, newest first.
     *
     * date_to includes the whole of that day.
     */
    public function search(SubmissionFilterData $filter, int $perPage): LengthAwarePaginator
    {
        return InvoiceSubmission::query()
            ->leftJoin('users', 'invoice_submissions.created_by', '=', 'users.id')
            ->leftJoin('invoices', 'invoice_submissions.invoice_id', '=', 'invoices.id')
            ->select([
                'invoice_submissions.*',
                'users.name as user_name',
                'users.email as user_email',
                'invoices.invoice_number',
                'invoices.total as invoice_total',
            ])
            ->when($filter->userId !== null, fn ($q) => $q->where('invoice_submissions.created_by', $filter->userId))
            ->when($filter->state !== null, fn ($q) => $q->where('invoice_submissions.state', $filter->state))
            ->when($filter->dateFrom !== null, fn ($q) => $q->where('invoice_submissions.created_at', '>=', $filter->dateFrom))
            ->when($filter->dateTo !== null, fn ($q) => $q->where('invoice_submissions.created_at', '<=', $filter->dateTo.' 23:59:59'))
            ->orderByDesc('invoice_submissions.created_at')
            ->paginate($perPage);
    }

    /**
     * One user's submissions with their invoice, newest first.
     */
    public function byUser(string $userId, int $perPage): LengthAwarePaginator
    {
        return InvoiceSubmission::query()
            ->leftJoin('invoices', 'invoice_submissions.invoice_id', '=', 'invoices.id')
            ->where('invoice_submissions.created_by', $userId)
            ->select([
                'invoice_submissions.*',
                'invoices.invoice_number',
                'invoices.total as invoice_total',
            ])
            ->orderByDesc('invoice_submissions.created_at')
            ->paginate($perPage);
    }

    /**
     * @return Collection<string, int|string>
     */
    private function groupByState(Builder $query): Collection
    {
        return $query
            ->selectRaw('state, COUNT(*) as total')
            ->groupBy('state')
            ->pluck('total', 'state');
    }
}
