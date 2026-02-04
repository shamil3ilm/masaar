<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Accounting\ChartOfAccount;
use App\Models\Accounting\JournalEntryLine;
use App\Models\Core\Organization;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProfitLossReportService
{
    /**
     * Generate Profit & Loss statement.
     */
    public function generate(
        int $organizationId,
        string $startDate,
        string $endDate,
        ?int $branchId = null,
        ?int $costCenterId = null,
        bool $compareLastPeriod = false
    ): ProfitLossReport {
        $organization = Organization::find($organizationId);
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();

        // Get comparison period if requested
        $compareStart = null;
        $compareEnd = null;
        if ($compareLastPeriod) {
            $periodDays = $start->diffInDays($end);
            $compareEnd = $start->copy()->subDay();
            $compareStart = $compareEnd->copy()->subDays($periodDays);
        }

        // Build report
        $revenue = $this->getRevenueSection($organizationId, $start, $end, $branchId, $costCenterId);
        $costOfSales = $this->getCostOfSalesSection($organizationId, $start, $end, $branchId, $costCenterId);
        $operatingExpenses = $this->getOperatingExpensesSection($organizationId, $start, $end, $branchId, $costCenterId);
        $otherIncome = $this->getOtherIncomeSection($organizationId, $start, $end, $branchId, $costCenterId);
        $otherExpenses = $this->getOtherExpensesSection($organizationId, $start, $end, $branchId, $costCenterId);

        // Calculate totals
        $totalRevenue = $this->sumSection($revenue);
        $totalCostOfSales = $this->sumSection($costOfSales);
        $grossProfit = bcsub($totalRevenue, $totalCostOfSales, 4);

        $totalOperatingExpenses = $this->sumSection($operatingExpenses);
        $operatingProfit = bcsub($grossProfit, $totalOperatingExpenses, 4);

        $totalOtherIncome = $this->sumSection($otherIncome);
        $totalOtherExpenses = $this->sumSection($otherExpenses);
        $netOther = bcsub($totalOtherIncome, $totalOtherExpenses, 4);

        $netProfitBeforeTax = bcadd($operatingProfit, $netOther, 4);

        // Tax expense (from tax expense accounts)
        $taxExpense = $this->getTaxExpense($organizationId, $start, $end, $branchId);
        $netProfitAfterTax = bcsub($netProfitBeforeTax, $taxExpense, 4);

        // Comparison data
        $comparison = null;
        if ($compareLastPeriod && $compareStart && $compareEnd) {
            $comparison = $this->generateComparisonData(
                $organizationId,
                $compareStart,
                $compareEnd,
                $branchId,
                $costCenterId
            );
        }

        return new ProfitLossReport(
            organizationName: $organization->name,
            startDate: $start->format('Y-m-d'),
            endDate: $end->format('Y-m-d'),
            currency: $organization->base_currency,
            revenue: $revenue,
            totalRevenue: $totalRevenue,
            costOfSales: $costOfSales,
            totalCostOfSales: $totalCostOfSales,
            grossProfit: $grossProfit,
            grossMarginPercent: $this->calculatePercentage($grossProfit, $totalRevenue),
            operatingExpenses: $operatingExpenses,
            totalOperatingExpenses: $totalOperatingExpenses,
            operatingProfit: $operatingProfit,
            operatingMarginPercent: $this->calculatePercentage($operatingProfit, $totalRevenue),
            otherIncome: $otherIncome,
            totalOtherIncome: $totalOtherIncome,
            otherExpenses: $otherExpenses,
            totalOtherExpenses: $totalOtherExpenses,
            netProfitBeforeTax: $netProfitBeforeTax,
            taxExpense: $taxExpense,
            netProfitAfterTax: $netProfitAfterTax,
            netProfitMarginPercent: $this->calculatePercentage($netProfitAfterTax, $totalRevenue),
            comparison: $comparison
        );
    }

    /**
     * Get revenue section (income accounts).
     */
    protected function getRevenueSection(int $orgId, Carbon $start, Carbon $end, ?int $branchId, ?int $costCenterId): array
    {
        return $this->getAccountBalances($orgId, 'income', 'sales', $start, $end, $branchId, $costCenterId);
    }

    /**
     * Get cost of sales section.
     */
    protected function getCostOfSalesSection(int $orgId, Carbon $start, Carbon $end, ?int $branchId, ?int $costCenterId): array
    {
        return $this->getAccountBalances($orgId, 'expense', 'cogs', $start, $end, $branchId, $costCenterId);
    }

    /**
     * Get operating expenses section.
     */
    protected function getOperatingExpensesSection(int $orgId, Carbon $start, Carbon $end, ?int $branchId, ?int $costCenterId): array
    {
        $subTypes = ['administrative', 'selling', 'operating', 'salaries', 'rent', 'utilities', 'depreciation'];
        return $this->getAccountBalances($orgId, 'expense', $subTypes, $start, $end, $branchId, $costCenterId);
    }

    /**
     * Get other income section.
     */
    protected function getOtherIncomeSection(int $orgId, Carbon $start, Carbon $end, ?int $branchId, ?int $costCenterId): array
    {
        $subTypes = ['other_income', 'interest_income', 'dividend_income', 'gain'];
        return $this->getAccountBalances($orgId, 'income', $subTypes, $start, $end, $branchId, $costCenterId);
    }

    /**
     * Get other expenses section.
     */
    protected function getOtherExpensesSection(int $orgId, Carbon $start, Carbon $end, ?int $branchId, ?int $costCenterId): array
    {
        $subTypes = ['other_expense', 'interest_expense', 'loss', 'finance_cost'];
        return $this->getAccountBalances($orgId, 'expense', $subTypes, $start, $end, $branchId, $costCenterId);
    }

    /**
     * Get tax expense.
     */
    protected function getTaxExpense(int $orgId, Carbon $start, Carbon $end, ?int $branchId): string
    {
        $accounts = $this->getAccountBalances($orgId, 'expense', 'tax', $start, $end, $branchId, null);
        return $this->sumSection($accounts);
    }

    /**
     * Get account balances for a section.
     */
    protected function getAccountBalances(
        int $orgId,
        string $type,
        string|array $subTypes,
        Carbon $start,
        Carbon $end,
        ?int $branchId,
        ?int $costCenterId
    ): array {
        if (is_string($subTypes)) {
            $subTypes = [$subTypes];
        }

        $accounts = ChartOfAccount::where('organization_id', $orgId)
            ->where('type', $type)
            ->whereIn('sub_type', $subTypes)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        $results = [];

        foreach ($accounts as $account) {
            $query = JournalEntryLine::where('account_id', $account->id)
                ->whereHas('journalEntry', function ($q) use ($orgId, $start, $end, $branchId) {
                    $q->where('organization_id', $orgId)
                        ->where('status', 'posted')
                        ->whereBetween('entry_date', [$start, $end]);

                    if ($branchId) {
                        $q->where('branch_id', $branchId);
                    }
                });

            if ($costCenterId) {
                $query->where('cost_center_id', $costCenterId);
            }

            // For income accounts, credit increases (positive), debit decreases
            // For expense accounts, debit increases (positive), credit decreases
            if ($type === 'income') {
                $balance = $query->selectRaw('COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) as balance')
                    ->value('balance') ?? '0';
            } else {
                $balance = $query->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as balance')
                    ->value('balance') ?? '0';
            }

            if (bccomp($balance, '0', 4) !== 0) {
                $results[] = [
                    'account_id' => $account->id,
                    'account_code' => $account->code,
                    'account_name' => $account->name,
                    'balance' => $balance,
                ];
            }
        }

        return $results;
    }

    /**
     * Sum all balances in a section.
     */
    protected function sumSection(array $section): string
    {
        $total = '0';
        foreach ($section as $item) {
            $total = bcadd($total, $item['balance'], 4);
        }
        return $total;
    }

    /**
     * Calculate percentage.
     */
    protected function calculatePercentage(string $value, string $base): string
    {
        if (bccomp($base, '0', 4) === 0) {
            return '0';
        }
        return bcmul(bcdiv($value, $base, 6), '100', 2);
    }

    /**
     * Generate comparison data for previous period.
     */
    protected function generateComparisonData(
        int $organizationId,
        Carbon $compareStart,
        Carbon $compareEnd,
        ?int $branchId,
        ?int $costCenterId
    ): array {
        $prevRevenue = $this->getRevenueSection($organizationId, $compareStart, $compareEnd, $branchId, $costCenterId);
        $prevCostOfSales = $this->getCostOfSalesSection($organizationId, $compareStart, $compareEnd, $branchId, $costCenterId);
        $prevOperatingExpenses = $this->getOperatingExpensesSection($organizationId, $compareStart, $compareEnd, $branchId, $costCenterId);

        $prevTotalRevenue = $this->sumSection($prevRevenue);
        $prevTotalCostOfSales = $this->sumSection($prevCostOfSales);
        $prevGrossProfit = bcsub($prevTotalRevenue, $prevTotalCostOfSales, 4);

        $prevTotalOperatingExpenses = $this->sumSection($prevOperatingExpenses);
        $prevOperatingProfit = bcsub($prevGrossProfit, $prevTotalOperatingExpenses, 4);

        return [
            'period' => $compareStart->format('Y-m-d') . ' to ' . $compareEnd->format('Y-m-d'),
            'total_revenue' => $prevTotalRevenue,
            'total_cost_of_sales' => $prevTotalCostOfSales,
            'gross_profit' => $prevGrossProfit,
            'total_operating_expenses' => $prevTotalOperatingExpenses,
            'operating_profit' => $prevOperatingProfit,
        ];
    }

    /**
     * Generate monthly trend report.
     */
    public function generateMonthlyTrend(
        int $organizationId,
        int $months = 12,
        ?int $branchId = null
    ): array {
        $trends = [];
        $endDate = now()->endOfMonth();

        for ($i = $months - 1; $i >= 0; $i--) {
            $monthStart = now()->subMonths($i)->startOfMonth();
            $monthEnd = $i === 0 ? $endDate : now()->subMonths($i)->endOfMonth();

            $revenue = $this->getRevenueSection($organizationId, $monthStart, $monthEnd, $branchId, null);
            $costOfSales = $this->getCostOfSalesSection($organizationId, $monthStart, $monthEnd, $branchId, null);
            $operatingExpenses = $this->getOperatingExpensesSection($organizationId, $monthStart, $monthEnd, $branchId, null);

            $totalRevenue = $this->sumSection($revenue);
            $totalCostOfSales = $this->sumSection($costOfSales);
            $grossProfit = bcsub($totalRevenue, $totalCostOfSales, 4);

            $totalOperatingExpenses = $this->sumSection($operatingExpenses);
            $netProfit = bcsub($grossProfit, $totalOperatingExpenses, 4);

            $trends[] = [
                'month' => $monthStart->format('Y-m'),
                'month_name' => $monthStart->format('M Y'),
                'revenue' => $totalRevenue,
                'cost_of_sales' => $totalCostOfSales,
                'gross_profit' => $grossProfit,
                'operating_expenses' => $totalOperatingExpenses,
                'net_profit' => $netProfit,
                'gross_margin' => $this->calculatePercentage($grossProfit, $totalRevenue),
                'net_margin' => $this->calculatePercentage($netProfit, $totalRevenue),
            ];
        }

        return $trends;
    }

    /**
     * Generate category-wise analysis.
     */
    public function generateCategoryAnalysis(
        int $organizationId,
        string $startDate,
        string $endDate,
        ?int $branchId = null
    ): array {
        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);

        // Get sales by product category
        $salesByCategory = DB::table('document_lines')
            ->join('invoices', function ($join) use ($organizationId, $start, $end, $branchId) {
                $join->on('document_lines.document_id', '=', 'invoices.id')
                    ->where('document_lines.document_type', '=', 'Invoice')
                    ->where('invoices.organization_id', '=', $organizationId)
                    ->where('invoices.status', '!=', 'draft')
                    ->whereBetween('invoices.invoice_date', [$start, $end]);

                if ($branchId) {
                    $join->where('invoices.branch_id', '=', $branchId);
                }
            })
            ->join('products', 'document_lines.product_id', '=', 'products.id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->selectRaw('
                COALESCE(categories.name, \'Uncategorized\') as category_name,
                SUM(document_lines.total) as total_sales,
                SUM(document_lines.quantity) as total_quantity,
                COUNT(DISTINCT invoices.id) as invoice_count
            ')
            ->groupBy('categories.name')
            ->orderByDesc('total_sales')
            ->get();

        $totalSales = $salesByCategory->sum('total_sales');

        return $salesByCategory->map(function ($category) use ($totalSales) {
            return [
                'category' => $category->category_name,
                'total_sales' => (string) $category->total_sales,
                'total_quantity' => (string) $category->total_quantity,
                'invoice_count' => $category->invoice_count,
                'percentage' => $totalSales > 0
                    ? round(($category->total_sales / $totalSales) * 100, 2)
                    : 0,
            ];
        })->toArray();
    }
}

class ProfitLossReport
{
    public function __construct(
        public readonly string $organizationName,
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly string $currency,
        public readonly array $revenue,
        public readonly string $totalRevenue,
        public readonly array $costOfSales,
        public readonly string $totalCostOfSales,
        public readonly string $grossProfit,
        public readonly string $grossMarginPercent,
        public readonly array $operatingExpenses,
        public readonly string $totalOperatingExpenses,
        public readonly string $operatingProfit,
        public readonly string $operatingMarginPercent,
        public readonly array $otherIncome,
        public readonly string $totalOtherIncome,
        public readonly array $otherExpenses,
        public readonly string $totalOtherExpenses,
        public readonly string $netProfitBeforeTax,
        public readonly string $taxExpense,
        public readonly string $netProfitAfterTax,
        public readonly string $netProfitMarginPercent,
        public readonly ?array $comparison = null
    ) {}

    public function toArray(): array
    {
        return [
            'organization' => $this->organizationName,
            'period' => [
                'start' => $this->startDate,
                'end' => $this->endDate,
            ],
            'currency' => $this->currency,
            'revenue' => [
                'items' => $this->revenue,
                'total' => $this->totalRevenue,
            ],
            'cost_of_sales' => [
                'items' => $this->costOfSales,
                'total' => $this->totalCostOfSales,
            ],
            'gross_profit' => $this->grossProfit,
            'gross_margin_percent' => $this->grossMarginPercent,
            'operating_expenses' => [
                'items' => $this->operatingExpenses,
                'total' => $this->totalOperatingExpenses,
            ],
            'operating_profit' => $this->operatingProfit,
            'operating_margin_percent' => $this->operatingMarginPercent,
            'other_income' => [
                'items' => $this->otherIncome,
                'total' => $this->totalOtherIncome,
            ],
            'other_expenses' => [
                'items' => $this->otherExpenses,
                'total' => $this->totalOtherExpenses,
            ],
            'net_profit_before_tax' => $this->netProfitBeforeTax,
            'tax_expense' => $this->taxExpense,
            'net_profit_after_tax' => $this->netProfitAfterTax,
            'net_profit_margin_percent' => $this->netProfitMarginPercent,
            'comparison' => $this->comparison,
        ];
    }
}
