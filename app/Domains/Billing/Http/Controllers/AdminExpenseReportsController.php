<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Controllers;

use App\Domains\Billing\Services\ExpenseReports;
use App\Domains\Billing\Services\Statements;
use App\Http\Controllers\Controller;
use App\Http\Listing\CsvExport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Admin › Finance › Expense reports, on one page for a period, and each as
 * a CSV. Every admin reads them.
 */
class AdminExpenseReportsController extends Controller
{
    public function __construct(
        private readonly ExpenseReports $reports,
    ) {}

    public function index(Request $request): View
    {
        [$from, $to] = $this->period($request);
        $groups = [];

        foreach (ExpenseReports::GROUPS as $by) {
            $groups[$by] = $this->reports->grouped($by, $from, $to);
        }

        return view('admin.finance.expense-reports', [
            'from' => $from,
            'to' => $to,
            'groups' => $groups,
            'vat' => $this->reports->vat($from, $to),
            'pnl' => $this->reports->profitAndLoss($from, $to),
            'payables' => $this->reports->payables($to),
            'headings' => Statements::headings(),
            'taxCurrency' => $this->reports->taxCurrency(),
        ]);
    }

    public function csv(Request $request, string $report): StreamedResponse
    {
        [$from, $to] = $this->period($request);
        $name = 'expenses-'.$report.'-'.$from->toDateString().'-'.$to->toDateString().'.csv';

        return match ($report) {
            'vat' => CsvExport::download($name, $this->columns([
                'date' => __('Date'), 'supplier' => __('Supplier'), 'number' => __('Supplier invoice'), 'treatment' => __('VAT treatment'),
                'net' => __('Net in tax currency'), 'vat' => __('VAT charged'), 'self_assessed' => __('Reverse charge output VAT'), 'recoverable' => __('Recoverable input VAT'),
            ]), $this->reports->vat($from, $to)['expenses']),
            'pnl' => CsvExport::download($name, $this->columns([
                'month' => __('Month'), ...ExpenseReports::pnlHeadings(), 'profit' => __('Profit'),
            ]), $this->pnlRows($this->reports->profitAndLoss($from, $to))),
            'payables' => CsvExport::download($name, $this->columns([
                'currency' => __('Currency'), 'name' => __('Supplier'), ...Statements::headings(), 'total' => __('Total'),
            ]), $this->ageingRows($this->reports->payables($to))),
            default => CsvExport::download($name, $this->columns([
                'name' => __('Name'), 'count' => __('Expenses'), 'net' => __('Net'), 'vat' => __('VAT'), 'recoverable' => __('Recoverable input VAT'), 'cost' => __('Cost'),
            ]), $this->reports->grouped($report, $from, $to)),
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
     * The months, then their total.
     *
     * @param  array{months: list<array<string, string>>, totals: array<string, string>}  $pnl
     * @return list<array<string, string>>
     */
    private function pnlRows(array $pnl): array
    {
        return [...$pnl['months'], ['month' => __('Total'), ...$pnl['totals']], ...array_map(fn (string $line): array => ['month' => __('Not converted: :line', ['line' => $line])], $pnl['unconverted'])];
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

            $rows[] = ['currency' => $currency, 'name' => __('All suppliers'), ...$table['totals']];
        }

        return $rows;
    }
}
