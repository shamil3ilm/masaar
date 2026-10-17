<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Compliance\Engine\Contracts\TaxReports;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Organization\Http\Concerns\ResolvesPortalOrganization;
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
 * files come from there. The period is this page's.
 */
class PortalReportController extends Controller
{
    use ResolvesPortalOrganization;

    /** A period longer than this is a mistake, and a slow one. */
    private const MAX_DAYS = 400;

    public function __construct(
        private readonly TenantResolver $tenant,
        private readonly JurisdictionResolver $jurisdictions,
    ) {}

    public function index(Request $request): View
    {
        [$from, $to] = $this->period($request);
        $page = $this->reports($request)->page($this->portalOrganizationId($request), $from, $to);

        return view($page->view, [
            'from' => $from,
            'to' => $to,
            ...$page->data,
            'presets' => $this->presets(),
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

    /** The organization's country's return, switched on or not: reading stays open. */
    private function reports(Request $request): TaxReports
    {
        return $this->jurisdictions->forRules($this->portalOrganization($this->tenant, $request))->reports();
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'])->startOfDay() : CarbonImmutable::now()->startOfMonth();
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'])->startOfDay() : CarbonImmutable::now()->endOfMonth()->startOfDay();

        if ($from->diffInDays($to, true) > self::MAX_DAYS) {
            $to = $from->addDays(self::MAX_DAYS);
        }

        return [$from, $to];
    }

    /**
     * @return array<string, array{from: string, to: string}>
     */
    private function presets(): array
    {
        $now = CarbonImmutable::now();
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
