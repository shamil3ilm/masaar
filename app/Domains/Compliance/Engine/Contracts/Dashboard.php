<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\Contracts;

use App\Domains\Compliance\Engine\DTOs\Panel;
use App\Domains\Organization\Models\Organization;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * An organization's dashboard figures that depend on how its country files:
 * the portal's, and the API's /api/dashboard.
 *
 * Every figure is the resolved tenant's: the caller has resolved the
 * organization before asking, as for every tenant-scoped report. The
 * invoice counts every country shares stay with the caller.
 */
interface Dashboard
{
    /** The portal dashboard's subheading. */
    public function heading(): string;

    /**
     * The portal dashboard below the setup steps: the country's view and its
     * figures. The figures are handed to the dashboard as they are, so a
     * test reads them as the view does.
     */
    public function portal(Organization $organization): Panel;

    /**
     * GET /api/dashboard's figures between the shared invoices and the time
     * it was generated.
     *
     * @return array<string, mixed>
     */
    public function overview(string $organizationId): array;

    /**
     * GET /api/dashboard/health: what filing depends on, before the time it
     * was checked, and a verdict on it - healthy, warning or critical.
     *
     * @return array{checks: array<string, mixed>, status: string}
     */
    public function health(string $organizationId): array;

    /**
     * GET /api/dashboard/submissions: what became of the organization's
     * filings.
     *
     * @return array<string, mixed>
     */
    public function filings(string $organizationId): array;

    /**
     * Filings per day since the given moment, by Y-m-d, for the usage chart.
     * A day with none may be left out.
     *
     * @return array<string, int>
     */
    public function dailyFilings(CarbonInterface $since): array;

    /** GET /api/dashboard/activity: the latest filing events, newest first. */
    public function activity(int $limit): Collection;
}
