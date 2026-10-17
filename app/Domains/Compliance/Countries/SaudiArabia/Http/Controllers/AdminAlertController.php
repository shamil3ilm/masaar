<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Http\Controllers;

use App\Domains\Compliance\Countries\SaudiArabia\Reports\DeadlineReport;
use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * What has missed its ZATCA reporting deadline, for the platform team.
 *
 * `fatoora:overdue-submissions` has found these rows hourly since before there
 * was a console, and said so to a log channel and to Slack. Both are places an
 * alert arrives, not places anyone can go and ask the question - so answering
 * it meant a psql prompt or tailing alerts.log. This is the same query behind a
 * URL.
 *
 * Deliberately read-only. Nothing here retries a submission: `admin/queue`
 * already does that for the offline queue, and a retry button that silently
 * does nothing for a row whose certificate has expired is worse than no
 * button.
 *
 * Reachable only behind platform.admin, which checks users.is_platform_admin
 * and is deliberately not the per-organization admin role.
 */
class AdminAlertController extends Controller
{
    public function __construct(
        private readonly DeadlineReport $deadlines,
    ) {}

    public function index(): View
    {
        $breakdown = $this->deadlines->breakdown();

        return view('admin.alerts.index', [
            'overdue' => $breakdown['overdue'],
            'atRisk' => $breakdown['at_risk'],
            // The totals, not the size of the list. The listing is capped so
            // the page stays readable during an incident; the counts must not
            // be, or the page would understate the incident.
            'overdueTotal' => $breakdown['overdue_total'],
            'atRiskTotal' => $breakdown['at_risk_total'],
            'cappedAt' => $breakdown['listing_capped_at'],
            'deadlineHours' => $breakdown['deadline_hours'],
            'warningHours' => $breakdown['warning_hours'],
        ]);
    }
}
