<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Accounting\Account;
use App\Models\Accounting\FiscalYear;
use App\Models\Accounting\JournalEntryLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AccountBalanceService
{
    /**
     * Get balance for a single account.
     */
    public function getAccountBalance(
        int $accountId,
        ?int $fiscalYearId = null,
        ?string $asOfDate = null,
        bool $includeOpening = true
    ): array {
        $account = Account::findOrFail($accountId);

        $movementQuery = JournalEntryLine::query()
            ->where('account_id', $accountId)
            ->whereHas('journalEntry', function ($q) use ($fiscalYearId, $asOfDate) {
                $q->where('status', 'posted');

                if ($fiscalYearId) {
                    $q->where('fiscal_year_id', $fiscalYearId);
                }

                if ($asOfDate) {
                    $q->whereDate('entry_date', '<=', $asOfDate);
                }
            });

        $totals = (clone $movementQuery)
            ->selectRaw('COALESCE(SUM(base_debit), 0) as total_debit, COALESCE(SUM(base_credit), 0) as total_credit')
            ->first();

        $openingBalance = 0;
        if ($includeOpening && $fiscalYearId) {
            $opening = $account->openingBalances()
                ->where('fiscal_year_id', $fiscalYearId)
                ->first();

            if ($opening) {
                $openingBalance = $account->isDebitNormal()
                    ? $opening->debit - $opening->credit
                    : $opening->credit - $opening->debit;
            }
        }

        $movementBalance = $account->isDebitNormal()
            ? $totals->total_debit - $totals->total_credit
            : $totals->total_credit - $totals->total_debit;

        return [
            'account_id' => $account->id,
            'account_code' => $account->code,
            'account_name' => $account->name,
            'account_type' => $account->account_type,
            'opening_balance' => $openingBalance,
            'total_debit' => (float) $totals->total_debit,
            'total_credit' => (float) $totals->total_credit,
            'movement' => $movementBalance,
            'closing_balance' => $openingBalance + $movementBalance,
        ];
    }

    /**
     * Get trial balance for organization.
     */
    public function getTrialBalance(
        int $organizationId,
        int $fiscalYearId,
        ?string $asOfDate = null
    ): array {
        $accounts = Account::where('organization_id', $organizationId)
            ->where('is_header', false)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        $trialBalance = [];
        $totalDebit = 0;
        $totalCredit = 0;

        foreach ($accounts as $account) {
            $balance = $this->getAccountBalance($account->id, $fiscalYearId, $asOfDate);

            // Only include accounts with balances
            if ($balance['closing_balance'] != 0) {
                $trialBalance[] = [
                    'account_code' => $account->code,
                    'account_name' => $account->name,
                    'account_type' => $account->account_type,
                    'debit' => $balance['closing_balance'] > 0 && $account->isDebitNormal()
                        ? $balance['closing_balance']
                        : ($balance['closing_balance'] < 0 && !$account->isDebitNormal()
                            ? abs($balance['closing_balance'])
                            : 0),
                    'credit' => $balance['closing_balance'] > 0 && !$account->isDebitNormal()
                        ? $balance['closing_balance']
                        : ($balance['closing_balance'] < 0 && $account->isDebitNormal()
                            ? abs($balance['closing_balance'])
                            : 0),
                ];

                if ($account->isDebitNormal()) {
                    if ($balance['closing_balance'] > 0) {
                        $totalDebit += $balance['closing_balance'];
                    } else {
                        $totalCredit += abs($balance['closing_balance']);
                    }
                } else {
                    if ($balance['closing_balance'] > 0) {
                        $totalCredit += $balance['closing_balance'];
                    } else {
                        $totalDebit += abs($balance['closing_balance']);
                    }
                }
            }
        }

        return [
            'as_of_date' => $asOfDate ?? now()->toDateString(),
            'fiscal_year_id' => $fiscalYearId,
            'accounts' => $trialBalance,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'is_balanced' => bccomp((string) $totalDebit, (string) $totalCredit, 2) === 0,
        ];
    }

    /**
     * Get balance sheet summary.
     */
    public function getBalanceSheetSummary(
        int $organizationId,
        int $fiscalYearId,
        ?string $asOfDate = null
    ): array {
        $accountTypes = [
            Account::TYPE_ASSET,
            Account::TYPE_LIABILITY,
            Account::TYPE_EQUITY,
        ];

        $summary = [];

        foreach ($accountTypes as $type) {
            $accounts = Account::where('organization_id', $organizationId)
                ->where('account_type', $type)
                ->where('is_header', false)
                ->where('is_active', true)
                ->get();

            $total = 0;
            foreach ($accounts as $account) {
                $balance = $this->getAccountBalance($account->id, $fiscalYearId, $asOfDate);
                $total += $balance['closing_balance'];
            }

            $summary[$type] = $total;
        }

        return [
            'as_of_date' => $asOfDate ?? now()->toDateString(),
            'total_assets' => $summary[Account::TYPE_ASSET],
            'total_liabilities' => $summary[Account::TYPE_LIABILITY],
            'total_equity' => $summary[Account::TYPE_EQUITY],
            'is_balanced' => bccomp(
                (string) $summary[Account::TYPE_ASSET],
                (string) ($summary[Account::TYPE_LIABILITY] + $summary[Account::TYPE_EQUITY]),
                2
            ) === 0,
        ];
    }

    /**
     * Get income statement (profit & loss) summary.
     */
    public function getIncomeStatementSummary(
        int $organizationId,
        int $fiscalYearId,
        ?string $startDate = null,
        ?string $endDate = null
    ): array {
        $fiscalYear = FiscalYear::findOrFail($fiscalYearId);

        $startDate = $startDate ?? $fiscalYear->start_date->toDateString();
        $endDate = $endDate ?? ($fiscalYear->is_closed ? $fiscalYear->end_date->toDateString() : now()->toDateString());

        // Get income totals
        $incomeAccounts = Account::where('organization_id', $organizationId)
            ->where('account_type', Account::TYPE_INCOME)
            ->where('is_header', false)
            ->where('is_active', true)
            ->get();

        $totalIncome = 0;
        foreach ($incomeAccounts as $account) {
            $balance = $this->getAccountBalance(
                $account->id,
                $fiscalYearId,
                $endDate,
                false // Don't include opening balance for P&L
            );
            $totalIncome += $balance['movement'];
        }

        // Get expense totals
        $expenseAccounts = Account::where('organization_id', $organizationId)
            ->where('account_type', Account::TYPE_EXPENSE)
            ->where('is_header', false)
            ->where('is_active', true)
            ->get();

        $totalExpenses = 0;
        foreach ($expenseAccounts as $account) {
            $balance = $this->getAccountBalance(
                $account->id,
                $fiscalYearId,
                $endDate,
                false
            );
            $totalExpenses += $balance['movement'];
        }

        return [
            'period_start' => $startDate,
            'period_end' => $endDate,
            'total_income' => abs($totalIncome), // Income should be positive
            'total_expenses' => abs($totalExpenses), // Expenses should be positive
            'net_profit' => abs($totalIncome) - abs($totalExpenses),
        ];
    }

    /**
     * Get account ledger (all transactions for an account).
     */
    public function getAccountLedger(
        int $accountId,
        ?int $fiscalYearId = null,
        ?string $startDate = null,
        ?string $endDate = null,
        int $limit = 100,
        int $offset = 0
    ): array {
        $account = Account::findOrFail($accountId);

        $query = JournalEntryLine::with(['journalEntry'])
            ->where('account_id', $accountId)
            ->whereHas('journalEntry', function ($q) use ($fiscalYearId, $startDate, $endDate) {
                $q->where('status', 'posted');

                if ($fiscalYearId) {
                    $q->where('fiscal_year_id', $fiscalYearId);
                }

                if ($startDate) {
                    $q->whereDate('entry_date', '>=', $startDate);
                }

                if ($endDate) {
                    $q->whereDate('entry_date', '<=', $endDate);
                }
            })
            ->orderBy(
                JournalEntryLine::query()
                    ->from('journal_entries')
                    ->whereColumn('journal_entries.id', 'journal_entry_lines.journal_entry_id')
                    ->select('entry_date')
            );

        $total = $query->count();
        $lines = $query->skip($offset)->take($limit)->get();

        $runningBalance = 0;
        $ledger = [];

        foreach ($lines as $line) {
            $entry = $line->journalEntry;

            if ($account->isDebitNormal()) {
                $runningBalance += ($line->base_debit - $line->base_credit);
            } else {
                $runningBalance += ($line->base_credit - $line->base_debit);
            }

            $ledger[] = [
                'date' => $entry->entry_date->toDateString(),
                'entry_number' => $entry->entry_number,
                'reference' => $entry->reference,
                'description' => $line->description ?? $entry->description,
                'debit' => (float) $line->base_debit,
                'credit' => (float) $line->base_credit,
                'balance' => $runningBalance,
            ];
        }

        return [
            'account' => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->account_type,
            ],
            'transactions' => $ledger,
            'total_count' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }
}
