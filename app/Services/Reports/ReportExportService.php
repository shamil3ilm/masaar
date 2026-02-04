<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Reports\ReportExecution;
use App\Models\Reports\SavedReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class ReportExportService
{
    protected int $organizationId;
    protected array $organizationData = [];

    public function setContext(int $organizationId, array $organizationData = []): self
    {
        $this->organizationId = $organizationId;
        $this->organizationData = $organizationData;
        return $this;
    }

    /**
     * Export report to specified format.
     */
    public function export(string $reportType, array $data, string $format = 'pdf', ?ReportExecution $execution = null): string
    {
        $execution?->markAsStarted();

        try {
            $filePath = match ($format) {
                'pdf' => $this->exportToPdf($reportType, $data),
                'xlsx', 'excel' => $this->exportToExcel($reportType, $data),
                'csv' => $this->exportToCsv($reportType, $data),
                'json' => $this->exportToJson($reportType, $data),
                default => throw new \InvalidArgumentException("Unsupported format: {$format}"),
            };

            $fullPath = storage_path('app/' . $filePath);
            $fileSize = file_exists($fullPath) ? filesize($fullPath) : 0;
            $rowCount = $this->countRows($data);

            $execution?->markAsCompleted($filePath, $format, $fileSize, $rowCount);

            return $filePath;
        } catch (\Exception $e) {
            $execution?->markAsFailed($e->getMessage());
            throw $e;
        }
    }

    /**
     * Export to PDF.
     */
    protected function exportToPdf(string $reportType, array $data): string
    {
        $viewName = $this->getViewName($reportType);
        $viewData = array_merge($data, [
            'organization' => (object) $this->organizationData,
            'report_title' => $this->getReportTitle($reportType),
        ]);

        $pdf = Pdf::loadView($viewName, $viewData)
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => true,
                'defaultFont' => 'DejaVu Sans',
            ]);

        $filename = $this->generateFilename($reportType, 'pdf');
        $path = "reports/{$this->organizationId}/{$filename}";

        Storage::put($path, $pdf->output());

        return $path;
    }

    /**
     * Export to Excel.
     */
    protected function exportToExcel(string $reportType, array $data): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Set document properties
        $spreadsheet->getProperties()
            ->setCreator($this->organizationData['name'] ?? 'ERP System')
            ->setTitle($this->getReportTitle($reportType))
            ->setDescription("Generated on " . now()->format('Y-m-d H:i:s'));

        // Build spreadsheet based on report type
        $this->buildSpreadsheet($sheet, $reportType, $data);

        // Auto-size columns
        foreach (range('A', $sheet->getHighestColumn()) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = $this->generateFilename($reportType, 'xlsx');
        $path = "reports/{$this->organizationId}/{$filename}";
        $fullPath = storage_path('app/' . $path);

        // Ensure directory exists
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($fullPath);

        return $path;
    }

    /**
     * Export to CSV.
     */
    protected function exportToCsv(string $reportType, array $data): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $this->buildSpreadsheet($sheet, $reportType, $data);

        $filename = $this->generateFilename($reportType, 'csv');
        $path = "reports/{$this->organizationId}/{$filename}";
        $fullPath = storage_path('app/' . $path);

        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $writer = new Csv($spreadsheet);
        $writer->setDelimiter(',');
        $writer->setEnclosure('"');
        $writer->save($fullPath);

        return $path;
    }

    /**
     * Export to JSON.
     */
    protected function exportToJson(string $reportType, array $data): string
    {
        $filename = $this->generateFilename($reportType, 'json');
        $path = "reports/{$this->organizationId}/{$filename}";

        Storage::put($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $path;
    }

    /**
     * Build spreadsheet content.
     */
    protected function buildSpreadsheet($sheet, string $reportType, array $data): void
    {
        match ($reportType) {
            'balance_sheet' => $this->buildBalanceSheet($sheet, $data),
            'income_statement', 'profit_loss' => $this->buildIncomeStatement($sheet, $data),
            'trial_balance' => $this->buildTrialBalance($sheet, $data),
            'cash_flow' => $this->buildCashFlow($sheet, $data),
            'general_ledger' => $this->buildGeneralLedger($sheet, $data),
            'aged_receivables' => $this->buildAgedReceivables($sheet, $data),
            'aged_payables' => $this->buildAgedPayables($sheet, $data),
            'stock_valuation' => $this->buildStockValuation($sheet, $data),
            'stock_movement' => $this->buildStockMovement($sheet, $data),
            'sales_by_customer' => $this->buildSalesByCustomer($sheet, $data),
            'sales_by_product' => $this->buildSalesByProduct($sheet, $data),
            'sales_by_salesperson' => $this->buildSalesBySalesperson($sheet, $data),
            default => $this->buildGenericReport($sheet, $data),
        };
    }

    /**
     * Build Balance Sheet spreadsheet.
     */
    protected function buildBalanceSheet($sheet, array $data): void
    {
        $row = 1;

        // Header
        $sheet->setCellValue('A' . $row, $this->organizationData['name'] ?? 'Company');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(14);
        $row++;

        $sheet->setCellValue('A' . $row, 'Balance Sheet');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(12);
        $row++;

        $sheet->setCellValue('A' . $row, 'As of: ' . ($data['as_of_date'] ?? now()->format('Y-m-d')));
        $row += 2;

        // Column headers
        $sheet->setCellValue('A' . $row, 'Account');
        $sheet->setCellValue('B' . $row, 'Current');
        if (isset($data['compare_to'])) {
            $sheet->setCellValue('C' . $row, 'Previous');
            $sheet->setCellValue('D' . $row, 'Change');
        }
        $this->styleHeaderRow($sheet, $row, 'D');
        $row++;

        // Assets
        $sheet->setCellValue('A' . $row, 'ASSETS');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true);
        $row++;

        foreach ($data['sections']['assets']['items'] ?? [] as $subType) {
            $sheet->setCellValue('A' . $row, '  ' . ($subType['label'] ?? ''));
            $sheet->getStyle('A' . $row)->getFont()->setBold(true);
            $row++;

            foreach ($subType['accounts'] ?? [] as $account) {
                $sheet->setCellValue('A' . $row, '    ' . $account['code'] . ' - ' . $account['name']);
                $sheet->setCellValue('B' . $row, $account['balance']);
                if (isset($account['previous_balance'])) {
                    $sheet->setCellValue('C' . $row, $account['previous_balance']);
                    $sheet->setCellValue('D' . $row, $account['change'] ?? 0);
                }
                $row++;
            }

            $sheet->setCellValue('A' . $row, '    Subtotal');
            $sheet->setCellValue('B' . $row, $subType['subtotal'] ?? 0);
            $sheet->getStyle('A' . $row . ':D' . $row)->getFont()->setItalic(true);
            $row++;
        }

        $sheet->setCellValue('A' . $row, 'Total Assets');
        $sheet->setCellValue('B' . $row, $data['sections']['assets']['total'] ?? 0);
        $this->styleTotalRow($sheet, $row, 'D');
        $row += 2;

        // Liabilities (similar structure)
        $sheet->setCellValue('A' . $row, 'LIABILITIES');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true);
        $row++;

        foreach ($data['sections']['liabilities']['items'] ?? [] as $subType) {
            $sheet->setCellValue('A' . $row, '  ' . ($subType['label'] ?? ''));
            $row++;

            foreach ($subType['accounts'] ?? [] as $account) {
                $sheet->setCellValue('A' . $row, '    ' . $account['code'] . ' - ' . $account['name']);
                $sheet->setCellValue('B' . $row, $account['balance']);
                $row++;
            }
        }

        $sheet->setCellValue('A' . $row, 'Total Liabilities');
        $sheet->setCellValue('B' . $row, $data['sections']['liabilities']['total'] ?? 0);
        $this->styleTotalRow($sheet, $row, 'D');
        $row += 2;

        // Equity
        $sheet->setCellValue('A' . $row, 'EQUITY');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true);
        $row++;

        foreach ($data['sections']['equity']['items'] ?? [] as $item) {
            foreach ($item['accounts'] ?? [] as $account) {
                $sheet->setCellValue('A' . $row, '  ' . ($account['code'] ?? '') . ' - ' . $account['name']);
                $sheet->setCellValue('B' . $row, $account['balance']);
                $row++;
            }
        }

        $sheet->setCellValue('A' . $row, 'Total Equity');
        $sheet->setCellValue('B' . $row, $data['sections']['equity']['total'] ?? 0);
        $this->styleTotalRow($sheet, $row, 'D');
        $row += 2;

        // Total
        $sheet->setCellValue('A' . $row, 'TOTAL LIABILITIES & EQUITY');
        $sheet->setCellValue('B' . $row, $data['summary']['total_liabilities_and_equity'] ?? 0);
        $this->styleTotalRow($sheet, $row, 'D');
    }

    /**
     * Build Trial Balance spreadsheet.
     */
    protected function buildTrialBalance($sheet, array $data): void
    {
        $row = 1;

        // Header
        $sheet->setCellValue('A' . $row, $this->organizationData['name'] ?? 'Company');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(14);
        $row++;

        $sheet->setCellValue('A' . $row, 'Trial Balance');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(12);
        $row++;

        $sheet->setCellValue('A' . $row, 'As of: ' . ($data['as_of_date'] ?? now()->format('Y-m-d')));
        $row += 2;

        // Column headers
        $sheet->setCellValue('A' . $row, 'Code');
        $sheet->setCellValue('B' . $row, 'Account');
        $sheet->setCellValue('C' . $row, 'Type');
        $sheet->setCellValue('D' . $row, 'Debit');
        $sheet->setCellValue('E' . $row, 'Credit');
        $this->styleHeaderRow($sheet, $row, 'E');
        $row++;

        // Accounts
        foreach ($data['accounts'] ?? [] as $account) {
            $sheet->setCellValue('A' . $row, $account['code']);
            $sheet->setCellValue('B' . $row, $account['name']);
            $sheet->setCellValue('C' . $row, ucfirst($account['type'] ?? ''));
            $sheet->setCellValue('D' . $row, $account['debit'] ?? 0);
            $sheet->setCellValue('E' . $row, $account['credit'] ?? 0);
            $row++;
        }

        // Totals
        $row++;
        $sheet->setCellValue('A' . $row, 'TOTALS');
        $sheet->setCellValue('D' . $row, $data['summary']['total_debit'] ?? 0);
        $sheet->setCellValue('E' . $row, $data['summary']['total_credit'] ?? 0);
        $this->styleTotalRow($sheet, $row, 'E');
    }

    /**
     * Build Stock Valuation spreadsheet.
     */
    protected function buildStockValuation($sheet, array $data): void
    {
        $row = 1;

        // Header
        $sheet->setCellValue('A' . $row, 'Stock Valuation Report');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(14);
        $row++;

        $sheet->setCellValue('A' . $row, 'As of: ' . ($data['as_of_date'] ?? now()->format('Y-m-d')));
        $row += 2;

        // Column headers
        $headers = ['SKU', 'Product', 'Category', 'Warehouse', 'Qty', 'Available', 'Unit Cost', 'Total Value'];
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . $row, $header);
            $col++;
        }
        $this->styleHeaderRow($sheet, $row, 'H');
        $row++;

        // Items
        foreach ($data['items'] ?? [] as $item) {
            $sheet->setCellValue('A' . $row, $item['sku'] ?? '');
            $sheet->setCellValue('B' . $row, $item['product_name'] ?? '');
            $sheet->setCellValue('C' . $row, $item['category'] ?? '');
            $sheet->setCellValue('D' . $row, $item['warehouse'] ?? '');
            $sheet->setCellValue('E' . $row, $item['quantity'] ?? 0);
            $sheet->setCellValue('F' . $row, $item['available'] ?? 0);
            $sheet->setCellValue('G' . $row, $item['unit_cost'] ?? 0);
            $sheet->setCellValue('H' . $row, $item['total_value'] ?? 0);
            $row++;
        }

        // Summary
        $row++;
        $sheet->setCellValue('A' . $row, 'TOTAL');
        $sheet->setCellValue('E' . $row, $data['summary']['total_quantity'] ?? 0);
        $sheet->setCellValue('H' . $row, $data['summary']['total_value'] ?? 0);
        $this->styleTotalRow($sheet, $row, 'H');
    }

    /**
     * Build Sales by Customer spreadsheet.
     */
    protected function buildSalesByCustomer($sheet, array $data): void
    {
        $row = 1;

        // Header
        $sheet->setCellValue('A' . $row, 'Sales by Customer Report');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(14);
        $row++;

        $sheet->setCellValue('A' . $row, 'Period: ' . ($data['period_start'] ?? '') . ' to ' . ($data['period_end'] ?? ''));
        $row += 2;

        // Column headers
        $headers = ['Rank', 'Customer', 'Invoices', 'Subtotal', 'Discount', 'Tax', 'Total', 'Paid', 'Outstanding', '%'];
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . $row, $header);
            $col++;
        }
        $this->styleHeaderRow($sheet, $row, 'J');
        $row++;

        // Customers
        foreach ($data['customers'] ?? [] as $customer) {
            $sheet->setCellValue('A' . $row, $customer['rank'] ?? '');
            $sheet->setCellValue('B' . $row, $customer['customer_name'] ?? '');
            $sheet->setCellValue('C' . $row, $customer['invoice_count'] ?? 0);
            $sheet->setCellValue('D' . $row, $customer['subtotal'] ?? 0);
            $sheet->setCellValue('E' . $row, $customer['discount'] ?? 0);
            $sheet->setCellValue('F' . $row, $customer['tax'] ?? 0);
            $sheet->setCellValue('G' . $row, $customer['total'] ?? 0);
            $sheet->setCellValue('H' . $row, $customer['paid'] ?? 0);
            $sheet->setCellValue('I' . $row, $customer['outstanding'] ?? 0);
            $sheet->setCellValue('J' . $row, ($customer['percentage_of_total'] ?? 0) . '%');
            $row++;
        }

        // Summary
        $row++;
        $sheet->setCellValue('A' . $row, 'TOTAL');
        $sheet->setCellValue('C' . $row, $data['summary']['total_invoices'] ?? 0);
        $sheet->setCellValue('G' . $row, $data['summary']['total_sales'] ?? 0);
        $sheet->setCellValue('H' . $row, $data['summary']['total_paid'] ?? 0);
        $sheet->setCellValue('I' . $row, $data['summary']['total_outstanding'] ?? 0);
        $this->styleTotalRow($sheet, $row, 'J');
    }

    /**
     * Build generic report.
     */
    protected function buildGenericReport($sheet, array $data): void
    {
        $row = 1;
        $sheet->setCellValue('A' . $row, 'Report Data');
        $row += 2;

        // If data has a common structure, try to extract it
        if (isset($data['items']) && is_array($data['items'])) {
            $items = $data['items'];
        } elseif (isset($data['data']) && is_array($data['data'])) {
            $items = $data['data'];
        } else {
            // Just dump the JSON
            $sheet->setCellValue('A' . $row, json_encode($data, JSON_PRETTY_PRINT));
            return;
        }

        if (empty($items)) {
            $sheet->setCellValue('A' . $row, 'No data available');
            return;
        }

        // Headers from first item
        $firstItem = reset($items);
        if (is_array($firstItem)) {
            $col = 'A';
            foreach (array_keys($firstItem) as $key) {
                $sheet->setCellValue($col . $row, ucfirst(str_replace('_', ' ', $key)));
                $col++;
            }
            $this->styleHeaderRow($sheet, $row, chr(ord('A') + count($firstItem) - 1));
            $row++;

            // Data
            foreach ($items as $item) {
                $col = 'A';
                foreach ($item as $value) {
                    $sheet->setCellValue($col . $row, is_array($value) ? json_encode($value) : $value);
                    $col++;
                }
                $row++;
            }
        }
    }

    // Additional builder methods...
    protected function buildIncomeStatement($sheet, array $data): void
    {
        $this->buildGenericReport($sheet, $data);
    }

    protected function buildCashFlow($sheet, array $data): void
    {
        $this->buildGenericReport($sheet, $data);
    }

    protected function buildGeneralLedger($sheet, array $data): void
    {
        $this->buildGenericReport($sheet, $data);
    }

    protected function buildAgedReceivables($sheet, array $data): void
    {
        $this->buildGenericReport($sheet, $data);
    }

    protected function buildAgedPayables($sheet, array $data): void
    {
        $this->buildGenericReport($sheet, $data);
    }

    protected function buildStockMovement($sheet, array $data): void
    {
        $this->buildGenericReport($sheet, $data);
    }

    protected function buildSalesByProduct($sheet, array $data): void
    {
        $this->buildGenericReport($sheet, $data);
    }

    protected function buildSalesBySalesperson($sheet, array $data): void
    {
        $this->buildGenericReport($sheet, $data);
    }

    /**
     * Style header row.
     */
    protected function styleHeaderRow($sheet, int $row, string $lastCol): void
    {
        $range = "A{$row}:{$lastCol}{$row}";

        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4'],
            ],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN],
            ],
        ]);
    }

    /**
     * Style total row.
     */
    protected function styleTotalRow($sheet, int $row, string $lastCol): void
    {
        $range = "A{$row}:{$lastCol}{$row}";

        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E2EFDA'],
            ],
            'borders' => [
                'top' => ['borderStyle' => Border::BORDER_DOUBLE],
                'bottom' => ['borderStyle' => Border::BORDER_DOUBLE],
            ],
        ]);
    }

    /**
     * Get view name for PDF.
     */
    protected function getViewName(string $reportType): string
    {
        $viewMap = [
            'balance_sheet' => 'exports.reports.balance-sheet',
            'income_statement' => 'exports.reports.income-statement',
            'profit_loss' => 'exports.reports.income-statement',
            'trial_balance' => 'exports.reports.trial-balance',
            'cash_flow' => 'exports.reports.cash-flow',
            'general_ledger' => 'exports.reports.general-ledger',
            'aged_receivables' => 'exports.reports.aged-receivables',
            'aged_payables' => 'exports.reports.aged-payables',
            'stock_valuation' => 'exports.reports.stock-valuation',
            'stock_movement' => 'exports.reports.stock-movement',
            'sales_by_customer' => 'exports.reports.sales-by-customer',
            'sales_by_product' => 'exports.reports.sales-by-product',
        ];

        return $viewMap[$reportType] ?? 'exports.reports.generic';
    }

    /**
     * Get report title.
     */
    protected function getReportTitle(string $reportType): string
    {
        return match ($reportType) {
            'balance_sheet' => 'Balance Sheet',
            'income_statement', 'profit_loss' => 'Income Statement',
            'trial_balance' => 'Trial Balance',
            'cash_flow' => 'Cash Flow Statement',
            'general_ledger' => 'General Ledger',
            'aged_receivables' => 'Aged Receivables Report',
            'aged_payables' => 'Aged Payables Report',
            'stock_valuation' => 'Stock Valuation Report',
            'stock_movement' => 'Stock Movement Report',
            'sales_by_customer' => 'Sales by Customer Report',
            'sales_by_product' => 'Sales by Product Report',
            'sales_by_salesperson' => 'Sales by Salesperson Report',
            default => ucwords(str_replace('_', ' ', $reportType)) . ' Report',
        };
    }

    /**
     * Generate filename.
     */
    protected function generateFilename(string $reportType, string $extension): string
    {
        $timestamp = now()->format('Ymd_His');
        return "{$reportType}_{$timestamp}.{$extension}";
    }

    /**
     * Count rows in report data.
     */
    protected function countRows(array $data): int
    {
        // Try to find the main data array
        foreach (['items', 'accounts', 'customers', 'products', 'movements', 'entries'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return count($data[$key]);
            }
        }

        return 0;
    }
}
