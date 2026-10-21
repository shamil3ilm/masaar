<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Controllers;

use App\Domains\Billing\Services\FinanceReports;
use App\Domains\Billing\Services\Statements;
use App\Http\Controllers\Controller;
use App\Http\Listing\CsvExport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Admin › Finance › Reports, on one page for a period, and each as a CSV.
 * Every admin reads them.
 */
class AdminFinanceReportsController extends Controller
{
    public function __construct(
        private readonly FinanceReports $reports,
    ) {}

    public function index(Request $request): View
    {
        [$from, $to] = $this->period($request);

        return view('admin.finance.reports', [
            'from' => $from,
            'to' => $to,
            'receivables' => $this->reports->receivables($from, $to),
            'payments' => $this->reports->payments($from, $to),
            'vat' => $this->reports->vat($from, $to),
            'collections' => $this->reports->collections($from, $to),
            'ageing' => $this->reports->ageing($to),
            'headings' => Statements::headings(),
        ]);
    }

    public function csv(Request $request, string $report): StreamedResponse
    {
        [$from, $to] = $this->period($request);
        $name = $report.'-'.$from->toDateString().'-'.$to->toDateString().'.csv';

        return match ($report) {
            'receivables' => CsvExport::download($name, $this->keyed(__('Currency'), [
                'count' => __('Invoices'), 'net' => __('Net'), 'vat' => __('VAT'), 'total' => __('Total'), 'paid' => __('Paid'),
                'by_credit' => __('Settled by credit'), 'credited' => __('Credited'), 'written_off' => __('Written off'), 'outstanding' => __('Outstanding'),
            ]), $this->rows($this->reports->receivables($from, $to))),
            'payments' => CsvExport::download($name, $this->columns([
                'method' => __('Method'), 'channel' => __('Channel'), 'currency' => __('Currency'), 'count' => __('Payments'),
                'received' => __('Received'), 'fees' => __('Fees'), 'paid_back' => __('Paid back'), 'net' => __('Net'),
            ]), $this->reports->payments($from, $to)),
            'vat' => CsvExport::download($name, $this->columns([
                'number' => __('Document'), 'date' => __('Date'), 'type' => __('Type'), 'customer' => __('Customer'), 'currency' => __('Currency'),
                'net' => __('Net'), 'vat' => __('VAT'), 'rate' => __('Exchange rate'), 'net_tax' => __('Net in tax currency'), 'vat_tax' => __('VAT in tax currency'),
            ]), $this->reports->vat($from, $to)['documents']),
            'collections' => CsvExport::download($name, $this->keyed(__('Currency'), [
                'fell_due' => __('Fell due'), 'collected' => __('Collected'), 'by_credit' => __('Settled by credit'), 'written_off' => __('Written off'),
                'average_days_to_pay' => __('Average days to pay'), 'overdue' => __('Overdue'), 'overdue_count' => __('Overdue invoices'),
            ]), $this->rows($this->reports->collections($from, $to))),
            'ageing' => CsvExport::download($name, $this->columns([
                'currency' => __('Currency'), 'name' => __('Customer'), ...Statements::headings(), 'total' => __('Total'),
            ]), $this->ageingRows($this->reports->ageing($to))),
            default => abort(404),
        };
    }

    /**
     * The period asked for: by default this month to today.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(Request $request): array
    {
        $to = $this->date($request->query('to')) ?? CarbonImmutable::now()->startOfDay();
        $from = $this->date($request->query('from')) ?? $to->startOfMonth();

        return [$from->min($to), $to];
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, string>  $headings  key => heading
     * @return array<string, callable(array<string, mixed>): string>
     */
    private function columns(array $headings): array
    {
        $columns = [];

        foreach ($headings as $key => $heading) {
            $columns[$heading] = fn (array $row): string => (string) ($row[$key] ?? '');
        }

        return $columns;
    }

    /**
     * @param  array<string, string>  $headings
     * @return array<string, callable(array<string, mixed>): string>
     */
    private function keyed(string $first, array $headings): array
    {
        return $this->columns(['currency' => $first, ...$headings]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $byCurrency
     * @return list<array<string, mixed>>
     */
    private function rows(array $byCurrency): array
    {
        return array_map(fn (string $currency, array $row): array => ['currency' => $currency, ...$row], array_keys($byCurrency), array_values($byCurrency));
    }

    /**
     * @param  array<string, array{rows: array<string, array<string, mixed>>, totals: array<string, string>}>  $ageing
     * @return list<array<string, mixed>>
     */
    private function ageingRows(array $ageing): array
    {
        $rows = [];

        foreach ($ageing as $currency => $table) {
            foreach ($table['rows'] as $row) {
                $rows[] = ['currency' => $currency, ...$row];
            }

            $rows[] = ['currency' => $currency, 'name' => __('All customers'), ...$table['totals']];
        }

        return $rows;
    }
}
