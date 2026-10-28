<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Books\Http\Concerns\ReadsBooksPeriod;
use App\Domains\Compliance\Engine\Contracts\TaxReports;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Invoice\Services\BranchBreakdown;
use App\Domains\Invoice\Services\GroupReport;
use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
use App\Domains\Organization\Services\OrganizationGroups;
use App\Domains\Organization\Services\TenantResolver;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The period's sales as the VAT return groups them, and the invoices behind
 * them as CSV - what an accountant asks for at the end of the month.
 *
 * Everyone in the organization, viewers included: a viewer is usually that
 * accountant.
 *
 * The return, its currency and which documents count are the
 * organization's country's (TaxReports): the page, its summary and both
 * files come from there. The period is this page's, and the branches are
 * the switcher's (BranchScope), which every figure and file reads.
 */
class PortalReportController extends Controller
{
    use ReadsBooksPeriod {
        period as private periodOf;
        day as private dayOf;
    }
    use ResolvesPortalOrganization;

    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly JurisdictionResolver $jurisdictions,
        private readonly BranchBreakdown $breakdown,
        private readonly OrganizationGroups $groups,
        private readonly GroupReport $group,
    ) {}

    /**
     * The branches the switcher shows, combined, and - when that is more
     * than one - the same period branch by branch beneath.
     */
    public function index(Request $request): View
    {
        [$from, $to] = $this->period($request);
        $organizationId = $this->portalOrganizationId($request);
        $reports = $this->reports($request);
        $page = $reports->page($organizationId, $from, $to);

        return view($page->view, [
            'from' => $from,
            'to' => $to,
            ...$page->data,
            'presets' => $this->presets($request),
            'branchBreakdown' => $this->breakdown->rows($organizationId, $reports, $from, $to),
        ]);
    }

    /**
     * The period across the companies of the organization's group that the
     * person belongs to: read-only, one line per company, each in its own
     * currency and filed on its own. Not found outside a group.
     */
    public function group(Request $request): View
    {
        $user = $request->user() ?? abort(401);
        $organizationId = $this->portalOrganizationId($request);
        $group = $this->groups->groupFor($user, $organizationId) ?? abort(404);
        [$from, $to] = $this->period($request);
        $rows = $this->group->rows($user, $this->groups->companies($user, $organizationId), $from, $to);

        return view('portal.group', [
            'group' => $group,
            'rows' => $rows,
            'sums' => $this->group->sums($rows),
            'from' => $from,
            'to' => $to,
            'presets' => $this->presets($request),
        ]);
    }

    public function invoices(Request $request): StreamedResponse
    {
        [$from, $to] = $this->period($request);
        $fileName = 'invoices-'.$from->toDateString().'-to-'.$to->toDateString().'.csv';

        return $this->reports($request)->invoicesCsv($this->portalOrganizationId($request), $from, $to, $fileName);
    }

    public function vat(Request $request): StreamedResponse
    {
        [$from, $to] = $this->period($request);
        $fileName = 'vat-summary-'.$from->toDateString().'-to-'.$to->toDateString().'.csv';

        return $this->reports($request)->summaryCsv($this->portalOrganizationId($request), $from, $to, $fileName);
    }

    /**
     * One day's close by device and by branch, where the country counts its
     * documents per device (TaxReports::dailyClose); not found elsewhere.
     */
    public function close(Request $request): View
    {
        $day = $this->day($request);
        $page = $this->reports($request)->dailyClose($this->portalOrganizationId($request), $day) ?? abort(404);

        return view($page->view, ['day' => $day, ...$page->data]);
    }

    public function closeCsv(Request $request): StreamedResponse
    {
        $day = $this->day($request);

        return $this->reports($request)->dailyCloseCsv($this->portalOrganizationId($request), $day, 'daily-close-'.$day->toDateString().'.csv')
            ?? abort(404);
    }

    /**
     * The day asked for, or today where the organization trades.
     */
    private function day(Request $request): CarbonImmutable
    {
        return $this->dayOf($request, $this->portalOrganization($this->tenant, $request), 'day');
    }

    /** The organization's country's return, switched on or not: reading stays open. */
    private function reports(Request $request): TaxReports
    {
        return $this->jurisdictions->forRules($this->portalOrganization($this->tenant, $request))->reports();
    }

    /**
     * As asked, or this month on the organization's clock, as Books reads a
     * period (ReadsBooksPeriod): the first hours of a month are that month
     * wherever the server is.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(Request $request): array
    {
        return $this->periodOf($request, $this->portalOrganization($this->tenant, $request));
    }

    /**
     * @return array<string, array{from: string, to: string}>
     */
    private function presets(Request $request): array
    {
        $now = $this->today($this->portalOrganization($this->tenant, $request));
        $lastMonth = $now->subMonthNoOverflow();
        $lastQuarter = $now->subQuarterNoOverflow();

        return [
            __('This month') => ['from' => $now->startOfMonth()->toDateString(), 'to' => $now->endOfMonth()->toDateString()],
            __('Last month') => ['from' => $lastMonth->startOfMonth()->toDateString(), 'to' => $lastMonth->endOfMonth()->toDateString()],
            __('This quarter') => ['from' => $now->startOfQuarter()->toDateString(), 'to' => $now->endOfQuarter()->toDateString()],
            __('Last quarter') => ['from' => $lastQuarter->startOfQuarter()->toDateString(), 'to' => $lastQuarter->endOfQuarter()->toDateString()],
        ];
    }
}
