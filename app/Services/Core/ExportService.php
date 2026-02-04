<?php

declare(strict_types=1);

namespace App\Services\Core;

use App\Models\Core\ExportJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExportService
{
    /**
     * Create an export job.
     */
    public function createExport(
        string $entityType,
        User $user,
        string $format = 'xlsx',
        ?array $filters = null,
        ?array $columns = null,
        array $options = []
    ): ExportJob {
        $this->validateEntityType($entityType);
        $this->validateFormat($format);

        return ExportJob::create([
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'entity_type' => $entityType,
            'format' => $format,
            'filters' => $filters,
            'columns' => $columns,
            'options' => $options,
            'status' => ExportJob::STATUS_PENDING,
        ]);
    }

    /**
     * Process an export job.
     */
    public function processExport(ExportJob $exportJob): ExportJob
    {
        $exportJob->markAsProcessing();

        try {
            $entityType = ExportJob::getEntityTypes()[$exportJob->entity_type] ?? null;
            if (!$entityType) {
                throw new \InvalidArgumentException("Invalid entity type: {$exportJob->entity_type}");
            }

            // Get data
            $data = $this->fetchData($exportJob);

            if ($data->isEmpty()) {
                $exportJob->markAsFailed();
                return $exportJob;
            }

            // Determine columns to export
            $columns = $exportJob->columns ?? array_keys($entityType['columns']);
            $columnLabels = array_intersect_key($entityType['columns'], array_flip($columns));

            // Generate file
            $filePath = $this->generateFile($exportJob, $data, $columns, $columnLabels);

            $exportJob->markAsCompleted(
                $filePath,
                basename($filePath),
                Storage::disk('local')->size($filePath),
                $data->count()
            );
        } catch (\Exception $e) {
            $exportJob->markAsFailed();
            \Log::error("Export failed: {$e->getMessage()}", [
                'export_id' => $exportJob->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $exportJob->fresh();
    }

    /**
     * Get export job status.
     */
    public function getStatus(ExportJob $exportJob): array
    {
        return [
            'id' => $exportJob->id,
            'uuid' => $exportJob->uuid,
            'entity_type' => $exportJob->entity_type,
            'format' => $exportJob->format,
            'status' => $exportJob->status,
            'total_records' => $exportJob->total_records,
            'file_name' => $exportJob->file_name,
            'file_size' => $exportJob->file_size,
            'download_url' => $exportJob->getDownloadUrl(),
            'is_ready' => $exportJob->isReady(),
            'is_expired' => $exportJob->isExpired(),
            'started_at' => $exportJob->started_at?->toIso8601String(),
            'completed_at' => $exportJob->completed_at?->toIso8601String(),
            'expires_at' => $exportJob->expires_at?->toIso8601String(),
        ];
    }

    /**
     * Download export file.
     */
    public function download(ExportJob $exportJob): ?string
    {
        if (!$exportJob->isReady()) {
            return null;
        }

        return Storage::disk('local')->path($exportJob->file_path);
    }

    /**
     * Get export history for organization.
     */
    public function getHistory(int $organizationId, ?string $entityType = null, int $limit = 20): Collection
    {
        $query = ExportJob::where('organization_id', $organizationId)
            ->orderByDesc('created_at');

        if ($entityType) {
            $query->where('entity_type', $entityType);
        }

        return $query->limit($limit)->get();
    }

    /**
     * Cleanup expired exports.
     */
    public function cleanupExpired(): int
    {
        $expiredExports = ExportJob::where('expires_at', '<', now())
            ->whereNotNull('file_path')
            ->get();

        $count = 0;
        foreach ($expiredExports as $export) {
            if ($export->file_path && Storage::disk('local')->exists($export->file_path)) {
                Storage::disk('local')->delete($export->file_path);
            }
            $export->delete();
            $count++;
        }

        return $count;
    }

    // ==================== Protected Methods ====================

    protected function validateEntityType(string $entityType): void
    {
        $types = ExportJob::getEntityTypes();
        if (!isset($types[$entityType])) {
            throw new \InvalidArgumentException("Invalid entity type: {$entityType}");
        }
    }

    protected function validateFormat(string $format): void
    {
        $validFormats = [ExportJob::FORMAT_XLSX, ExportJob::FORMAT_CSV, ExportJob::FORMAT_PDF];
        if (!in_array($format, $validFormats)) {
            throw new \InvalidArgumentException("Invalid format: {$format}");
        }
    }

    protected function fetchData(ExportJob $exportJob): Collection
    {
        $query = $this->getQueryForEntity($exportJob);

        // Apply filters
        if (!empty($exportJob->filters)) {
            $query = $this->applyFilters($query, $exportJob->filters, $exportJob->entity_type);
        }

        return $query->get();
    }

    protected function getQueryForEntity(ExportJob $exportJob): Builder
    {
        $organizationId = $exportJob->organization_id;

        return match ($exportJob->entity_type) {
            ExportJob::ENTITY_CUSTOMERS => \App\Models\Sales\Contact::query()
                ->where('organization_id', $organizationId)
                ->where('contact_type', 'customer'),

            ExportJob::ENTITY_SUPPLIERS => \App\Models\Sales\Contact::query()
                ->where('organization_id', $organizationId)
                ->where('contact_type', 'supplier'),

            ExportJob::ENTITY_PRODUCTS => \App\Models\Inventory\Product::query()
                ->where('organization_id', $organizationId)
                ->with(['category', 'unit']),

            ExportJob::ENTITY_INVOICES => \App\Models\Sales\Invoice::query()
                ->where('organization_id', $organizationId)
                ->with(['customer']),

            ExportJob::ENTITY_EMPLOYEES => \App\Models\HR\Employee::query()
                ->where('organization_id', $organizationId)
                ->with(['department', 'designation']),

            ExportJob::ENTITY_CHART_OF_ACCOUNTS => \App\Models\Accounting\ChartOfAccount::query()
                ->where('organization_id', $organizationId)
                ->with(['parent']),

            ExportJob::ENTITY_STOCK_LEVELS => \App\Models\Inventory\StockLevel::query()
                ->where('organization_id', $organizationId)
                ->with(['product', 'warehouse']),

            ExportJob::ENTITY_LEADS => \App\Models\CRM\Lead::query()
                ->where('organization_id', $organizationId),

            default => throw new \InvalidArgumentException("No query defined for: {$exportJob->entity_type}"),
        };
    }

    protected function applyFilters(Builder $query, array $filters, string $entityType): Builder
    {
        foreach ($filters as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (is_array($value)) {
                if (isset($value['from']) || isset($value['to'])) {
                    // Date range
                    if (!empty($value['from'])) {
                        $query->where($field, '>=', $value['from']);
                    }
                    if (!empty($value['to'])) {
                        $query->where($field, '<=', $value['to']);
                    }
                } else {
                    // Multiple values
                    $query->whereIn($field, $value);
                }
            } else {
                $query->where($field, $value);
            }
        }

        return $query;
    }

    protected function generateFile(ExportJob $exportJob, Collection $data, array $columns, array $columnLabels): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Header row
        $col = 1;
        foreach ($columnLabels as $label) {
            $sheet->setCellValueByColumnAndRow($col, 1, $label);
            $col++;
        }

        // Style header
        $headerStyle = [
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E0E0E0'],
            ],
        ];
        $sheet->getStyle("A1:{$sheet->getHighestColumn()}1")->applyFromArray($headerStyle);

        // Data rows
        $row = 2;
        foreach ($data as $item) {
            $col = 1;
            foreach ($columns as $column) {
                $value = $this->getColumnValue($item, $column, $exportJob->entity_type);
                $sheet->setCellValueByColumnAndRow($col, $row, $value);
                $col++;
            }
            $row++;
        }

        // Auto-size columns
        foreach (range('A', $sheet->getHighestColumn()) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Generate file
        $fileName = "{$exportJob->entity_type}_{$exportJob->uuid}.{$exportJob->format}";
        $filePath = "exports/{$exportJob->organization_id}/{$fileName}";

        Storage::disk('local')->makeDirectory("exports/{$exportJob->organization_id}");
        $fullPath = Storage::disk('local')->path($filePath);

        if ($exportJob->format === ExportJob::FORMAT_CSV) {
            $writer = new Csv($spreadsheet);
        } else {
            $writer = new Xlsx($spreadsheet);
        }

        $writer->save($fullPath);

        return $filePath;
    }

    protected function getColumnValue($item, string $column, string $entityType): mixed
    {
        // Handle special cases for relationships and computed values
        return match ($entityType) {
            ExportJob::ENTITY_PRODUCTS => $this->getProductValue($item, $column),
            ExportJob::ENTITY_EMPLOYEES => $this->getEmployeeValue($item, $column),
            ExportJob::ENTITY_CHART_OF_ACCOUNTS => $this->getAccountValue($item, $column),
            ExportJob::ENTITY_STOCK_LEVELS => $this->getStockLevelValue($item, $column),
            ExportJob::ENTITY_INVOICES => $this->getInvoiceValue($item, $column),
            default => $item->{$column} ?? '',
        };
    }

    protected function getProductValue($product, string $column): mixed
    {
        return match ($column) {
            'category' => $product->category?->name ?? '',
            'unit' => $product->unit?->name ?? '',
            'stock_on_hand' => $product->stockLevels?->sum('quantity') ?? 0,
            'stock_value' => $product->stockLevels?->sum(fn ($sl) => $sl->quantity * $sl->average_cost) ?? 0,
            'is_active' => $product->is_active ? 'Yes' : 'No',
            default => $product->{$column} ?? '',
        };
    }

    protected function getEmployeeValue($employee, string $column): mixed
    {
        return match ($column) {
            'department' => $employee->department?->name ?? '',
            'designation' => $employee->designation?->name ?? '',
            'status' => ucfirst($employee->employment_status ?? ''),
            default => $employee->{$column} ?? '',
        };
    }

    protected function getAccountValue($account, string $column): mixed
    {
        return match ($column) {
            'parent' => $account->parent?->name ?? '',
            'is_active' => $account->is_active ? 'Yes' : 'No',
            'balance' => $account->getCurrentBalance() ?? 0,
            default => $account->{$column} ?? '',
        };
    }

    protected function getStockLevelValue($stockLevel, string $column): mixed
    {
        return match ($column) {
            'product_sku' => $stockLevel->product?->sku ?? '',
            'product_name' => $stockLevel->product?->name ?? '',
            'warehouse' => $stockLevel->warehouse?->name ?? '',
            'available_quantity' => $stockLevel->quantity - ($stockLevel->reserved_quantity ?? 0),
            'stock_value' => $stockLevel->quantity * ($stockLevel->average_cost ?? 0),
            default => $stockLevel->{$column} ?? '',
        };
    }

    protected function getInvoiceValue($invoice, string $column): mixed
    {
        return match ($column) {
            'customer_name' => $invoice->customer?->company_name ?? $invoice->customer_name ?? '',
            'status' => ucfirst($invoice->status ?? ''),
            'compliance_status' => ucfirst($invoice->compliance_status ?? 'N/A'),
            default => $invoice->{$column} ?? '',
        };
    }
}
