<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Accounting\ChartOfAccount;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalEntryLine;
use App\Models\Sales\Invoice;
use App\Models\Purchase\Bill;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FinancialReportService
{
    /**
     * Get Profit & Loss statement.
     */
    public function getProfitAndLoss(Carbon $startDate, Carbon $endDate): array
    {
        // Get all income accounts
        $incomeAccounts = ChartOfAccount::where('type', 'income')
            ->with(['journalLines' => function ($query) use ($startDate, $endDate) {
                $query->whereHas('journalEntry', function ($q) use ($startDate, $endDate) {
                    $q->where('status', 'posted')
                        ->whereBetween('entry_date', [$startDate, $endDate]);
                });
            }])
            ->get();

        $totalIncome = 0;
        $incomeBreakdown = [];

        foreach ($incomeAccounts as $account) {
            $credit = $account->journalLines->sum('credit');
            $debit = $account->journalLines->sum('debit');
            $balance = $credit - $debit; // Income is credit

            if ($balance != 0) {
                $incomeBreakdown[] = [
                    'account_id' => $account->id,
                    'account_code' => $account->code,
                    'account_name' => $account->name,
                    'amount' => (float) $balance,
                ];
                $totalIncome = bcadd((string) $totalIncome, (string) $balance, 4);
            }
        }

        // Get all expense accounts
        $expenseAccounts = ChartOfAccount::where('type', 'expense')
            ->with(['journalLines' => function ($query) use ($startDate, $endDate) {
                $query->whereHas('journalEntry', function ($q) use ($startDate, $endDate) {
                    $q->where('status', 'posted')
                        ->whereBetween('entry_date', [$startDate, $endDate]);
                });
            }])
            ->get();

        $totalExpenses = 0;
        $expenseBreakdown = [];

        foreach ($expenseAccounts as $account) {
            $debit = $account->journalLines->sum('debit');
            $credit = $account->journalLines->sum('credit');
            $balance = $debit - $credit; // Expense is debit

            if ($balance != 0) {
                $expenseBreakdown[] = [
                    'account_id' => $account->id,
                    'account_code' => $account->code,
                    'account_name' => $account->name,
                    'amount' => (float) $balance,
                ];
                $totalExpenses = bcadd((string) $totalExpenses, (string) $balance, 4);
            }
        }

        $netProfit = bcsub((string) $totalIncome, (string) $totalExpenses, 4);

        return [
            'period' => [
                'start' => $startDate->format('Y-m-d'),
                'end' => $endDate->format('Y-m-d'),
            ],
            'income' => [
                'total' => (float) $totalIncome,
                'breakdown' => $incomeBreakdown,
            ],
            'expenses' => [
                'total' => (float) $totalExpenses,
                'breakdown' => $expenseBreakdown,
            ],
            'net_profit' => (float) $netProfit,
            'profit_margin' => $totalIncome > 0
                ? round(((float) $netProfit / (float) $totalIncome) * 100, 2)
                : 0,
        ];
    }

    /**
     * Get Balance Sheet.
     */
    public function getBalanceSheet(Carbon $asOfDate): array
    {
        $accounts = ChartOfAccount::with(['journalLines' => function ($query) use ($asOfDate) {
            $query->whereHas('journalEntry', function ($q) use ($asOfDate) {
                $q->where('status', 'posted')
                    ->where('entry_date', '<=', $asOfDate);
            });
        }])
            ->get();

        $assets = ['current' => [], 'fixed' => [], 'other' => []];
        $liabilities = ['current' => [], 'long_term' => []];
        $equity = [];

        $totalAssets = 0;
        $totalLiabilities = 0;
        $totalEquity = 0;

        foreach ($accounts as $account) {
            $debit = $account->journalLines->sum('debit');
            $credit = $account->journalLines->sum('credit');

            $balance = match ($account->type) {
                'asset' => $debit - $credit, // Assets are debit balance
                'liability', 'equity' => $credit - $debit, // Liabilities/Equity are credit balance
                default => 0,
            };

            if ($balance == 0) {
                continue;
            }

            $item = [
                'account_id' => $account->id,
                'account_code' => $account->code,
                'account_name' => $account->name,
                'amount' => (float) $balance,
            ];

            switch ($account->type) {
                case 'asset':
                    if (in_array($account->sub_type, ['bank', 'cash', 'receivable', 'inventory'])) {
                        $assets['current'][] = $item;
                    } elseif ($account->sub_type === 'fixed_asset') {
                        $assets['fixed'][] = $item;
                    } else {
                        $assets['other'][] = $item;
                    }
                    $totalAssets = bcadd((string) $totalAssets, (string) $balance, 4);
                    break;

                case 'liability':
                    if (in_array($account->sub_type, ['payable', 'current_liability'])) {
                        $liabilities['current'][] = $item;
                    } else {
                        $liabilities['long_term'][] = $item;
                    }
                    $totalLiabilities = bcadd((string) $totalLiabilities, (string) $balance, 4);
                    break;

                case 'equity':
                    $equity[] = $item;
                    $totalEquity = bcadd((string) $totalEquity, (string) $balance, 4);
                    break;
            }
        }

        // Add current period net income to retained earnings
        $currentYearStart = $asOfDate->copy()->startOfYear();
        $pnl = $this->getProfitAndLoss($currentYearStart, $asOfDate);
        $retainedEarnings = (float) $pnl['net_profit'];

        $equity[] = [
            'account_code' => 'RE',
            'account_name' => 'Current Year Earnings',
            'amount' => $retainedEarnings,
        ];
        $totalEquity = bcadd((string) $totalEquity, (string) $retainedEarnings, 4);

        return [
            'as_of_date' => $asOfDate->format('Y-m-d'),
            'assets' => [
                'current_assets' => $assets['current'],
                'fixed_assets' => $assets['fixed'],
                'other_assets' => $assets['other'],
                'total' => (float) $totalAssets,
            ],
            'liabilities' => [
                'current_liabilities' => $liabilities['current'],
                'long_term_liabilities' => $liabilities['long_term'],
                'total' => (float) $totalLiabilities,
            ],
            'equity' => [
                'items' => $equity,
                'total' => (float) $totalEquity,
            ],
            'total_liabilities_and_equity' => (float) bcadd((string) $totalLiabilities, (string) $totalEquity, 4),
            'is_balanced' => bccomp((string) $totalAssets, bcadd((string) $totalLiabilities, (string) $totalEquity, 4), 2) === 0,
        ];
    }

    /**
     * Get Cash Flow statement.
     */
    public function getCashFlow(Carbon $startDate, Carbon $endDate): array
    {
        // Get bank/cash account IDs
        $cashAccountIds = ChartOfAccount::where('type', 'asset')
            ->whereIn('sub_type', ['bank', 'cash'])
            ->pluck('id');

        // Get all journal entries affecting cash accounts
        $cashMovements = JournalEntryLine::whereIn('account_id', $cashAccountIds)
            ->whereHas('journalEntry', function ($query) use ($startDate, $endDate) {
                $query->where('status', 'posted')
                    ->whereBetween('entry_date', [$startDate, $endDate]);
            })
            ->with(['journalEntry'])
            ->get();

        $operatingCashFlow = 0;
        $investingCashFlow = 0;
        $financingCashFlow = 0;

        $operatingActivities = [];
        $investingActivities = [];
        $financingActivities = [];

        foreach ($cashMovements as $line) {
            $cashChange = bcsub((string) $line->debit, (string) $line->credit, 4);
            $sourceType = $line->journalEntry->source_type ?? '';

            $activity = [
                'date' => $line->journalEntry->entry_date->format('Y-m-d'),
                'reference' => $line->journalEntry->reference,
                'description' => $line->description ?? $line->journalEntry->description,
                'amount' => (float) $cashChange,
            ];

            // Classify based on source type
            if (str_contains($sourceType, 'Invoice') || str_contains($sourceType, 'Bill') || str_contains($sourceType, 'Payment')) {
                $operatingActivities[] = $activity;
                $operatingCashFlow = bcadd((string) $operatingCashFlow, (string) $cashChange, 4);
            } elseif (str_contains($sourceType, 'Asset') || str_contains($sourceType, 'Depreciation')) {
                $investingActivities[] = $activity;
                $investingCashFlow = bcadd((string) $investingCashFlow, (string) $cashChange, 4);
            } else {
                $financingActivities[] = $activity;
                $financingCashFlow = bcadd((string) $financingCashFlow, (string) $cashChange, 4);
            }
        }

        // Get opening and closing balances
        $openingBalance = JournalEntryLine::whereIn('account_id', $cashAccountIds)
            ->whereHas('journalEntry', function ($query) use ($startDate) {
                $query->where('status', 'posted')
                    ->where('entry_date', '<', $startDate);
            })
            ->selectRaw('SUM(debit) - SUM(credit) as balance')
            ->first()
            ->balance ?? 0;

        $netCashChange = bcadd(
            bcadd((string) $operatingCashFlow, (string) $investingCashFlow, 4),
            (string) $financingCashFlow,
            4
        );

        $closingBalance = bcadd((string) $openingBalance, (string) $netCashChange, 4);

        return [
            'period' => [
                'start' => $startDate->format('Y-m-d'),
                'end' => $endDate->format('Y-m-d'),
            ],
            'opening_balance' => (float) $openingBalance,
            'operating_activities' => [
                'items' => $operatingActivities,
                'total' => (float) $operatingCashFlow,
            ],
            'investing_activities' => [
                'items' => $investingActivities,
                'total' => (float) $investingCashFlow,
            ],
            'financing_activities' => [
                'items' => $financingActivities,
                'total' => (float) $financingCashFlow,
            ],
            'net_cash_change' => (float) $netCashChange,
            'closing_balance' => (float) $closingBalance,
        ];
    }

    /**
     * Get Accounts Receivable Aging report.
     */
    public function getReceivableAging(): array
    {
        $today = now();

        $aging = Invoice::whereIn('status', ['sent', 'partial', 'overdue'])
            ->where('amount_due', '>', 0)
            ->with('customer:id,company_name,contact_name')
            ->get()
            ->map(function ($invoice) use ($today) {
                $daysOverdue = max(0, $invoice->due_date->diffInDays($today, false));

                return [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'customer_id' => $invoice->customer_id,
                    'customer_name' => $invoice->customer?->company_name ?? $invoice->customer_name,
                    'invoice_date' => $invoice->invoice_date->format('Y-m-d'),
                    'due_date' => $invoice->due_date->format('Y-m-d'),
                    'total' => (float) $invoice->total,
                    'amount_due' => (float) $invoice->amount_due,
                    'days_overdue' => $daysOverdue,
                    'aging_bucket' => $this->getAgingBucket($daysOverdue),
                ];
            });

        // Group by aging bucket
        $buckets = [
            'current' => $aging->where('aging_bucket', 'current')->sum('amount_due'),
            '1_30' => $aging->where('aging_bucket', '1_30')->sum('amount_due'),
            '31_60' => $aging->where('aging_bucket', '31_60')->sum('amount_due'),
            '61_90' => $aging->where('aging_bucket', '61_90')->sum('amount_due'),
            'over_90' => $aging->where('aging_bucket', 'over_90')->sum('amount_due'),
        ];

        return [
            'as_of_date' => $today->format('Y-m-d'),
            'summary' => [
                'current' => (float) $buckets['current'],
                '1_30_days' => (float) $buckets['1_30'],
                '31_60_days' => (float) $buckets['31_60'],
                '61_90_days' => (float) $buckets['61_90'],
                'over_90_days' => (float) $buckets['over_90'],
                'total' => (float) $aging->sum('amount_due'),
            ],
            'details' => $aging->sortByDesc('days_overdue')->values()->toArray(),
        ];
    }

    /**
     * Get Accounts Payable Aging report.
     */
    public function getPayableAging(): array
    {
        $today = now();

        $aging = Bill::whereIn('status', ['approved', 'partial', 'overdue'])
            ->where('amount_due', '>', 0)
            ->with('supplier:id,company_name,contact_name')
            ->get()
            ->map(function ($bill) use ($today) {
                $daysOverdue = max(0, $bill->due_date->diffInDays($today, false));

                return [
                    'bill_id' => $bill->id,
                    'bill_number' => $bill->bill_number,
                    'supplier_id' => $bill->supplier_id,
                    'supplier_name' => $bill->supplier?->company_name ?? $bill->supplier_name,
                    'bill_date' => $bill->bill_date->format('Y-m-d'),
                    'due_date' => $bill->due_date->format('Y-m-d'),
                    'total' => (float) $bill->total,
                    'amount_due' => (float) $bill->amount_due,
                    'days_overdue' => $daysOverdue,
                    'aging_bucket' => $this->getAgingBucket($daysOverdue),
                ];
            });

        $buckets = [
            'current' => $aging->where('aging_bucket', 'current')->sum('amount_due'),
            '1_30' => $aging->where('aging_bucket', '1_30')->sum('amount_due'),
            '31_60' => $aging->where('aging_bucket', '31_60')->sum('amount_due'),
            '61_90' => $aging->where('aging_bucket', '61_90')->sum('amount_due'),
            'over_90' => $aging->where('aging_bucket', 'over_90')->sum('amount_due'),
        ];

        return [
            'as_of_date' => $today->format('Y-m-d'),
            'summary' => [
                'current' => (float) $buckets['current'],
                '1_30_days' => (float) $buckets['1_30'],
                '31_60_days' => (float) $buckets['31_60'],
                '61_90_days' => (float) $buckets['61_90'],
                'over_90_days' => (float) $buckets['over_90'],
                'total' => (float) $aging->sum('amount_due'),
            ],
            'details' => $aging->sortByDesc('days_overdue')->values()->toArray(),
        ];
    }

    /**
     * Get aging bucket for days overdue.
     */
    protected function getAgingBucket(int $daysOverdue): string
    {
        return match (true) {
            $daysOverdue <= 0 => 'current',
            $daysOverdue <= 30 => '1_30',
            $daysOverdue <= 60 => '31_60',
            $daysOverdue <= 90 => '61_90',
            default => 'over_90',
        };
    }

    /**
     * Get Trial Balance.
     */
    public function getTrialBalance(Carbon $asOfDate): array
    {
        $accounts = ChartOfAccount::with(['journalLines' => function ($query) use ($asOfDate) {
            $query->whereHas('journalEntry', function ($q) use ($asOfDate) {
                $q->where('status', 'posted')
                    ->where('entry_date', '<=', $asOfDate);
            });
        }])
            ->orderBy('code')
            ->get();

        $lines = [];
        $totalDebit = 0;
        $totalCredit = 0;

        foreach ($accounts as $account) {
            $debit = $account->journalLines->sum('debit');
            $credit = $account->journalLines->sum('credit');

            if ($debit == 0 && $credit == 0) {
                continue;
            }

            // Calculate balance based on account type
            $balance = match ($account->type) {
                'asset', 'expense' => bcsub((string) $debit, (string) $credit, 4),
                default => bcsub((string) $credit, (string) $debit, 4),
            };

            $balanceDebit = $balance > 0 ? (float) $balance : 0;
            $balanceCredit = $balance < 0 ? (float) abs($balance) : 0;

            $lines[] = [
                'account_id' => $account->id,
                'account_code' => $account->code,
                'account_name' => $account->name,
                'account_type' => $account->type,
                'debit' => $balanceDebit,
                'credit' => $balanceCredit,
            ];

            $totalDebit = bcadd((string) $totalDebit, (string) $balanceDebit, 4);
            $totalCredit = bcadd((string) $totalCredit, (string) $balanceCredit, 4);
        }

        return [
            'as_of_date' => $asOfDate->format('Y-m-d'),
            'lines' => $lines,
            'totals' => [
                'debit' => (float) $totalDebit,
                'credit' => (float) $totalCredit,
            ],
            'is_balanced' => bccomp((string) $totalDebit, (string) $totalCredit, 2) === 0,
        ];
    }
}
