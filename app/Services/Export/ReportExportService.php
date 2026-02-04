<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Services\Reports\FinancialReportService;
use Illuminate\Support\Carbon;

class ReportExportService
{
    public function __construct(
        private ExportService $exportService,
        private FinancialReportService $financialReportService
    ) {}

    /**
     * Export Trial Balance to CSV.
     */
    public function exportTrialBalance(Carbon $asOfDate): string
    {
        $report = $this->financialReportService->getTrialBalance($asOfDate);

        $columns = [
            'account_code' => 'Account Code',
            'account_name' => 'Account Name',
            'account_type' => 'Type',
            'debit' => 'Debit',
            'credit' => 'Credit',
        ];

        $data = collect($report['lines']);

        // Add totals row
        $data->push([
            'account_code' => '',
            'account_name' => 'TOTAL',
            'account_type' => '',
            'debit' => $report['totals']['debit'],
            'credit' => $report['totals']['credit'],
        ]);

        $filename = "trial_balance_{$asOfDate->format('Y-m-d')}";

        return $this->exportService->toCsv($data, $columns, $filename);
    }

    /**
     * Export Profit & Loss to CSV.
     */
    public function exportProfitAndLoss(Carbon $startDate, Carbon $endDate): string
    {
        $report = $this->financialReportService->getProfitAndLoss($startDate, $endDate);

        $data = collect();

        // Income section
        $data->push([
            'category' => 'INCOME',
            'account_code' => '',
            'account_name' => '',
            'amount' => '',
        ]);

        foreach ($report['income']['breakdown'] as $item) {
            $data->push([
                'category' => '',
                'account_code' => $item['account_code'],
                'account_name' => $item['account_name'],
                'amount' => $item['amount'],
            ]);
        }

        $data->push([
            'category' => '',
            'account_code' => '',
            'account_name' => 'Total Income',
            'amount' => $report['income']['total'],
        ]);

        // Expenses section
        $data->push([
            'category' => 'EXPENSES',
            'account_code' => '',
            'account_name' => '',
            'amount' => '',
        ]);

        foreach ($report['expenses']['breakdown'] as $item) {
            $data->push([
                'category' => '',
                'account_code' => $item['account_code'],
                'account_name' => $item['account_name'],
                'amount' => $item['amount'],
            ]);
        }

        $data->push([
            'category' => '',
            'account_code' => '',
            'account_name' => 'Total Expenses',
            'amount' => $report['expenses']['total'],
        ]);

        // Net Profit
        $data->push([
            'category' => '',
            'account_code' => '',
            'account_name' => 'NET PROFIT',
            'amount' => $report['net_profit'],
        ]);

        $columns = [
            'category' => 'Category',
            'account_code' => 'Account Code',
            'account_name' => 'Account Name',
            'amount' => 'Amount',
        ];

        $filename = "profit_loss_{$startDate->format('Y-m-d')}_to_{$endDate->format('Y-m-d')}";

        return $this->exportService->toCsv($data, $columns, $filename);
    }

    /**
     * Export Receivable Aging to CSV.
     */
    public function exportReceivableAging(): string
    {
        $report = $this->financialReportService->getReceivableAging();

        $columns = [
            'invoice_number' => 'Invoice Number',
            'customer_name' => 'Customer',
            'invoice_date' => 'Invoice Date',
            'due_date' => 'Due Date',
            'total' => 'Total',
            'amount_due' => 'Amount Due',
            'days_overdue' => 'Days Overdue',
            'aging_bucket' => 'Aging',
        ];

        $data = collect($report['details'])->map(fn($item) => [
            'invoice_number' => $item['invoice_number'],
            'customer_name' => $item['customer_name'],
            'invoice_date' => $item['invoice_date'],
            'due_date' => $item['due_date'],
            'total' => $item['total'],
            'amount_due' => $item['amount_due'],
            'days_overdue' => $item['days_overdue'],
            'aging_bucket' => $this->formatAgingBucket($item['aging_bucket']),
        ]);

        $filename = "receivable_aging_" . now()->format('Y-m-d');

        return $this->exportService->toCsv($data, $columns, $filename);
    }

    /**
     * Export Payable Aging to CSV.
     */
    public function exportPayableAging(): string
    {
        $report = $this->financialReportService->getPayableAging();

        $columns = [
            'bill_number' => 'Bill Number',
            'supplier_name' => 'Supplier',
            'bill_date' => 'Bill Date',
            'due_date' => 'Due Date',
            'total' => 'Total',
            'amount_due' => 'Amount Due',
            'days_overdue' => 'Days Overdue',
            'aging_bucket' => 'Aging',
        ];

        $data = collect($report['details'])->map(fn($item) => [
            'bill_number' => $item['bill_number'],
            'supplier_name' => $item['supplier_name'],
            'bill_date' => $item['bill_date'],
            'due_date' => $item['due_date'],
            'total' => $item['total'],
            'amount_due' => $item['amount_due'],
            'days_overdue' => $item['days_overdue'],
            'aging_bucket' => $this->formatAgingBucket($item['aging_bucket']),
        ]);

        $filename = "payable_aging_" . now()->format('Y-m-d');

        return $this->exportService->toCsv($data, $columns, $filename);
    }

    /**
     * Format aging bucket for display.
     */
    protected function formatAgingBucket(string $bucket): string
    {
        return match ($bucket) {
            'current' => 'Current',
            '1_30' => '1-30 Days',
            '31_60' => '31-60 Days',
            '61_90' => '61-90 Days',
            'over_90' => 'Over 90 Days',
            default => $bucket,
        };
    }
}
