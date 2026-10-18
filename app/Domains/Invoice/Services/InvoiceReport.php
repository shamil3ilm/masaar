<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Services\BranchScope;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Counts of the current tenant's invoices.
 *
 * Invoice uses BelongsToTenant, so the global scope confines every query here
 * to the tenant the request resolved; none adds an org_id condition by hand.
 * Each reads the branches the dashboard shows (BranchScope): the one chosen
 * in the switcher, or all those the person may see.
 */
class InvoiceReport
{
    public function __construct(
        private readonly BranchScope $branches,
    ) {}

    /**
     * Invoices created at or after a moment.
     */
    public function countSince(DateTimeInterface $since): int
    {
        return $this->invoices()->where('created_at', '>=', $since)->count();
    }

    /**
     * Invoice counts for today, this month and last month, by type, and the
     * amount invoiced overall.
     *
     * One query for the counts and sum, and one grouped by type.
     *
     * @return array{total: int, today: int, this_month: int, last_month: int, by_type: array<string, int>, total_amount: float}
     */
    public function summary(): array
    {
        $today = now()->startOfDay();
        $thisMonth = now()->startOfMonth();
        $lastMonth = now()->subMonth()->startOfMonth();

        $stats = $this->invoices()
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as today,
                SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as this_month,
                SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) as last_month,
                COALESCE(SUM(total), 0) as total_amount
            ', [$today, $thisMonth, $lastMonth, $thisMonth])
            ->first();

        $byType = $this->invoices()
            ->selectRaw('type, COUNT(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();

        return [
            'total' => (int) ($stats->total ?? 0),
            'today' => (int) ($stats->today ?? 0),
            'this_month' => (int) ($stats->this_month ?? 0),
            'last_month' => (int) ($stats->last_month ?? 0),
            'by_type' => $byType,
            'total_amount' => (float) ($stats->total_amount ?? 0),
        ];
    }

    /**
     * Invoices created per day since a moment, keyed by Y-m-d.
     *
     * Days with none are absent.
     *
     * @return array<string, int>
     */
    public function dailyCounts(DateTimeInterface $since): array
    {
        return $this->invoices()
            ->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('count', 'date')
            ->toArray();
    }

    /**
     * @return Builder<Invoice>
     */
    private function invoices(): Builder
    {
        return $this->branches->applyVisible(Invoice::query());
    }
}
