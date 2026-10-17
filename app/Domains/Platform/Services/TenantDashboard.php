<?php

declare(strict_types=1);

namespace App\Domains\Platform\Services;

use App\Domains\Compliance\Engine\Contracts\Dashboard;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Services\InvoiceReport;
use App\Domains\Organization\Models\Organization;
use Illuminate\Support\Collection;

/**
 * One organization's dashboard, assembled from the domains that own each part.
 *
 * Every figure comes from a tenant-scoped report, so the caller must have
 * resolved the organization before asking. The invoices are every
 * country's; what became of their filings - and what filing depends on - is
 * the organization's country's (Dashboard).
 */
class TenantDashboard
{
    public function __construct(
        private readonly InvoiceReport $invoices,
        private readonly JurisdictionResolver $jurisdictions,
    ) {}

    /**
     * Invoices, and the country's filings, at a glance.
     */
    public function overview(string $organizationId): array
    {
        return [
            'invoices' => $this->invoices->summary(),
            ...$this->country($organizationId)->overview($organizationId),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * The state of what filing depends on, and an overall verdict.
     */
    public function health(string $organizationId): array
    {
        $health = $this->country($organizationId)->health($organizationId);

        return [
            ...$health['checks'],
            'checked_at' => now()->toIso8601String(),
            'status' => $health['status'],
        ];
    }

    /**
     * What became of the organization's filings.
     */
    public function submissions(string $organizationId): array
    {
        return $this->country($organizationId)->filings($organizationId);
    }

    /**
     * Invoices and filings per day over the last given days, oldest first,
     * with every day present.
     */
    public function usage(string $organizationId, int $days): array
    {
        $since = now()->subDays($days)->startOfDay();
        $invoices = $this->invoices->dailyCounts($since);
        $submissions = $this->country($organizationId)->dailyFilings($since);

        $dates = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $dates[] = [
                'date' => $date,
                'invoices' => $invoices[$date] ?? 0,
                'submissions' => $submissions[$date] ?? 0,
            ];
        }

        return [
            'period' => "{$days}d",
            'data' => $dates,
        ];
    }

    /**
     * The latest filing events, newest first. Asked with or without an
     * organization, as the activity route always has been; with none, the
     * default country's events in whatever the tenant scope allows.
     */
    public function activity(?string $organizationId, int $limit): Collection
    {
        return $this->country($organizationId)->activity($limit);
    }

    /**
     * The organization's country, switched on or not: a dashboard reads what
     * was filed, which a switch does not take away.
     */
    private function country(?string $organizationId): Dashboard
    {
        $organization = $organizationId === null ? null : Organization::query()->find($organizationId);

        return $this->jurisdictions->forRules($organization)->dashboard();
    }
}
