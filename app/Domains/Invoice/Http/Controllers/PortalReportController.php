<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Controllers;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Reports\UaeVatSummary;
use App\Domains\Invoice\Services\InvoiceCsv;
use App\Domains\Invoice\Services\VatSummary;
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
 * A UAE organization's sales are summarised by UAE tax category in dirhams
 * instead (UaeVatSummary): the Saudi return's boxes, riyals and ZATCA hash
 * describe none of its invoices.
 */
class PortalReportController extends Controller
{
    use ResolvesPortalOrganization;

    /** A period longer than this is a mistake, and a slow one. */
    private const MAX_DAYS = 400;

    public function __construct(
        private readonly VatSummary $summary,
        private readonly UaeVatSummary $uaeSummary,
        private readonly InvoiceCsv $csv,
        private readonly TenantResolver $tenant,
    ) {}

    public function index(Request $request): View
    {
        [$from, $to] = $this->period($request);

        if ($this->inUae($request)) {
            return view('portal.reports-uae', [
                'from' => $from,
                'to' => $to,
                'summary' => $this->uaeSummary->for($this->portalOrganizationId($request), $from, $to),
                'presets' => $this->presets(),
            ]);
        }

        return view('portal.reports', [
            'from' => $from,
            'to' => $to,
            'summary' => $this->summary->for($this->portalOrganizationId($request), $from, $to),
            'presets' => $this->presets(),
        ]);
    }

    public function invoices(Request $request): StreamedResponse
    {
        [$from, $to] = $this->period($request);
        $fileName = 'invoices-'.$from->toDateString().'-to-'.$to->toDateString().'.csv';

        if ($this->inUae($request)) {
            return $this->csv->uaeInvoices($this->portalOrganizationId($request), $from, $to, $this->uaeSummary->currency(), $fileName);
        }

        return $this->csv->invoices($this->portalOrganizationId($request), $from, $to, $fileName);
    }

    public function vat(Request $request): StreamedResponse
    {
        [$from, $to] = $this->period($request);
        $fileName = 'vat-summary-'.$from->toDateString().'-to-'.$to->toDateString().'.csv';

        if ($this->inUae($request)) {
            return $this->csv->uaeSummary($this->uaeSummary->for($this->portalOrganizationId($request), $from, $to), $from, $to, $fileName);
        }

        return $this->csv->summary($this->portalOrganizationId($request), $from, $to, $fileName);
    }

    private function inUae(Request $request): bool
    {
        return $this->portalOrganization($this->tenant, $request)->country === 'AE';
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
