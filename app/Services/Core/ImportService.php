<?php

declare(strict_types=1);

namespace App\Services\Core;

use App\Models\Core\ImportJob;
use App\Models\Core\ImportTemplate;
use App\Models\Core\Organization;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class ImportService
{
    protected array $importers = [];

    /**
     * Upload and create an import job.
     */
    public function uploadFile(
        UploadedFile $file,
        string $entityType,
        User $user,
        ?array $columnMapping = null,
        array $options = []
    ): ImportJob {
        $this->validateEntityType($entityType);

        $fileName = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $filePath = $file->storeAs(
            "imports/{$user->organization_id}",
            $fileName,
            'local'
        );

        $importJob = ImportJob::create([
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'entity_type' => $entityType,
            'file_name' => $fileName,
            'file_path' => $filePath,
            'original_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'status' => ImportJob::STATUS_PENDING,
            'column_mapping' => $columnMapping,
            'options' => array_merge([
                'update_existing' => false,
                'skip_errors' => true,
                'dry_run' => false,
            ], $options),
        ]);

        return $importJob;
    }

    /**
     * Preview file contents and detect columns.
     */
    public function previewFile(ImportJob $importJob, int $limit = 10): array
    {
        $rows = $this->readFile($importJob, $limit + 1);

        if (empty($rows)) {
            return [
                'headers' => [],
                'rows' => [],
                'total_rows' => 0,
            ];
        }

        $headers = array_shift($rows);
        $entityType = ImportJob::getEntityTypes()[$importJob->entity_type] ?? null;

        // Try to auto-map columns
        $suggestedMapping = $this->suggestColumnMapping($headers, $entityType['fields'] ?? []);

        return [
            'headers' => $headers,
            'rows' => array_slice($rows, 0, $limit),
            'total_rows' => $this->countRows($importJob),
            'entity_fields' => $entityType['fields'] ?? [],
            'suggested_mapping' => $suggestedMapping,
        ];
    }

    /**
     * Validate import before processing.
     */
    public function validateImport(ImportJob $importJob): array
    {
        $importJob->update(['status' => ImportJob::STATUS_VALIDATING]);

        $entityType = ImportJob::getEntityTypes()[$importJob->entity_type] ?? null;
        if (!$entityType) {
            return ['valid' => false, 'errors' => ['Invalid entity type']];
        }

        $mapping = $importJob->column_mapping;
        if (!$mapping) {
            return ['valid' => false, 'errors' => ['Column mapping is required']];
        }

        // Check required fields are mapped
        $mappedFields = array_values($mapping);
        $missingRequired = array_diff($entityType['required_fields'], $mappedFields);

        if (!empty($missingRequired)) {
            return [
                'valid' => false,
                'errors' => ['Missing required fields: ' . implode(', ', $missingRequired)],
            ];
        }

        // Validate sample rows
        $rows = $this->readFile($importJob, 100);
        if (empty($rows)) {
            return ['valid' => false, 'errors' => ['File is empty or could not be read']];
        }

        $headers = array_shift($rows);
        $validationErrors = [];

        foreach (array_slice($rows, 0, 50) as $index => $row) {
            $rowErrors = $this->validateRow($row, $headers, $mapping, $entityType['fields']);
            if (!empty($rowErrors)) {
                $validationErrors[] = [
                    'row' => $index + 2, // +2 for header and 0-index
                    'errors' => $rowErrors,
                ];
            }
        }

        $totalRows = $this->countRows($importJob);
        $importJob->update(['total_rows' => $totalRows]);

        return [
            'valid' => empty($validationErrors),
            'total_rows' => $totalRows,
            'validation_errors' => array_slice($validationErrors, 0, 20),
            'has_more_errors' => count($validationErrors) > 20,
        ];
    }

    /**
     * Process the import.
     */
    public function processImport(ImportJob $importJob): ImportJob
    {
        $importJob->markAsProcessing();

        try {
            $entityType = ImportJob::getEntityTypes()[$importJob->entity_type] ?? null;
            $rows = $this->readFile($importJob);

            if (empty($rows)) {
                $importJob->markAsFailed('File is empty');
                return $importJob;
            }

            $headers = array_shift($rows);
            $mapping = $importJob->column_mapping;
            $options = $importJob->options ?? [];

            $importJob->update(['total_rows' => count($rows)]);

            $importer = $this->getImporter($importJob->entity_type);

            DB::beginTransaction();

            try {
                foreach ($rows as $index => $row) {
                    $rowNumber = $index + 2; // +2 for header and 0-index

                    try {
                        $data = $this->mapRowToData($row, $headers, $mapping, $entityType['fields']);

                        // Skip empty rows
                        if ($this->isEmptyRow($data, $entityType['required_fields'])) {
                            $importJob->incrementProcessed(true, true);
                            continue;
                        }

                        // Validate row
                        $errors = $this->validateRow($row, $headers, $mapping, $entityType['fields']);
                        if (!empty($errors)) {
                            if ($options['skip_errors'] ?? true) {
                                $importJob->addError($rowNumber, implode(', ', $errors), ['data' => array_slice($data, 0, 5)]);
                                $importJob->incrementProcessed(false);
                                continue;
                            } else {
                                throw new \Exception(implode(', ', $errors));
                            }
                        }

                        // Process row
                        if (!($options['dry_run'] ?? false)) {
                            $importer->importRow($data, $importJob, $options);
                        }

                        $importJob->incrementProcessed(true);
                    } catch (\Exception $e) {
                        $importJob->addError($rowNumber, $e->getMessage());
                        $importJob->incrementProcessed(false);

                        if (!($options['skip_errors'] ?? true)) {
                            throw $e;
                        }
                    }
                }

                if (!($options['dry_run'] ?? false)) {
                    DB::commit();
                } else {
                    DB::rollBack();
                }

                $importJob->markAsCompleted([
                    'total_rows' => $importJob->total_rows,
                    'success' => $importJob->success_rows,
                    'failed' => $importJob->failed_rows,
                    'skipped' => $importJob->skipped_rows,
                    'dry_run' => $options['dry_run'] ?? false,
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                $importJob->markAsFailed($e->getMessage());
            }
        } catch (\Exception $e) {
            $importJob->markAsFailed($e->getMessage());
        }

        return $importJob->fresh();
    }

    /**
     * Get import job status.
     */
    public function getStatus(ImportJob $importJob): array
    {
        return [
            'id' => $importJob->id,
            'uuid' => $importJob->uuid,
            'entity_type' => $importJob->entity_type,
            'status' => $importJob->status,
            'progress' => $importJob->progress,
            'total_rows' => $importJob->total_rows,
            'processed_rows' => $importJob->processed_rows,
            'success_rows' => $importJob->success_rows,
            'failed_rows' => $importJob->failed_rows,
            'skipped_rows' => $importJob->skipped_rows,
            'errors' => array_slice($importJob->errors ?? [], 0, 50),
            'summary' => $importJob->summary,
            'started_at' => $importJob->started_at?->toIso8601String(),
            'completed_at' => $importJob->completed_at?->toIso8601String(),
        ];
    }

    /**
     * Cancel an import.
     */
    public function cancelImport(ImportJob $importJob): bool
    {
        if ($importJob->isProcessing()) {
            // Can't cancel while processing (would need queue job handling)
            return false;
        }

        $importJob->update(['status' => ImportJob::STATUS_CANCELLED]);

        // Clean up file
        if ($importJob->file_path) {
            Storage::disk('local')->delete($importJob->file_path);
        }

        return true;
    }

    /**
     * Get import history for organization.
     */
    public function getHistory(int $organizationId, ?string $entityType = null, int $limit = 20): Collection
    {
        $query = ImportJob::where('organization_id', $organizationId)
            ->orderByDesc('created_at');

        if ($entityType) {
            $query->where('entity_type', $entityType);
        }

        return $query->limit($limit)->get();
    }

    /**
     * Create or update import template.
     */
    public function saveTemplate(
        int $organizationId,
        string $name,
        string $entityType,
        array $columnMapping,
        array $options = [],
        bool $isDefault = false
    ): ImportTemplate {
        $template = ImportTemplate::updateOrCreate(
            [
                'organization_id' => $organizationId,
                'name' => $name,
                'entity_type' => $entityType,
            ],
            [
                'column_mapping' => $columnMapping,
                'options' => $options,
                'is_default' => $isDefault,
            ]
        );

        if ($isDefault) {
            $template->setAsDefault();
        }

        return $template;
    }

    /**
     * Get templates for entity type.
     */
    public function getTemplates(int $organizationId, ?string $entityType = null): Collection
    {
        $query = ImportTemplate::where('organization_id', $organizationId);

        if ($entityType) {
            $query->where('entity_type', $entityType);
        }

        return $query->get();
    }

    /**
     * Generate sample import file.
     */
    public function generateSampleFile(string $entityType): string
    {
        $this->validateEntityType($entityType);

        $entityConfig = ImportJob::getEntityTypes()[$entityType];
        $fields = $entityConfig['fields'];

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Header row
        $col = 1;
        foreach ($fields as $field => $config) {
            $sheet->setCellValueByColumnAndRow($col, 1, $config['label']);
            $col++;
        }

        // Sample data row
        $col = 1;
        foreach ($fields as $field => $config) {
            $sampleValue = $this->getSampleValue($field, $config);
            $sheet->setCellValueByColumnAndRow($col, 2, $sampleValue);
            $col++;
        }

        $fileName = "{$entityType}_import_template.xlsx";
        $filePath = "temp/{$fileName}";

        Storage::disk('local')->makeDirectory('temp');

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save(Storage::disk('local')->path($filePath));

        return $filePath;
    }

    // ==================== Protected Methods ====================

    protected function validateEntityType(string $entityType): void
    {
        $types = ImportJob::getEntityTypes();
        if (!isset($types[$entityType])) {
            throw new \InvalidArgumentException("Invalid entity type: {$entityType}");
        }
    }

    protected function readFile(ImportJob $importJob, ?int $limit = null): array
    {
        $filePath = Storage::disk('local')->path($importJob->file_path);
        $extension = pathinfo($importJob->original_name, PATHINFO_EXTENSION);

        if (in_array(strtolower($extension), ['xlsx', 'xls'])) {
            return $this->readExcel($filePath, $limit);
        } elseif (strtolower($extension) === 'csv') {
            return $this->readCsv($filePath, $limit);
        }

        throw new \InvalidArgumentException("Unsupported file type: {$extension}");
    }

    protected function readExcel(string $filePath, ?int $limit = null): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = [];

        $highestRow = $limit ? min($sheet->getHighestRow(), $limit) : $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();

        for ($row = 1; $row <= $highestRow; $row++) {
            $rowData = [];
            for ($col = 'A'; $col <= $highestColumn; $col++) {
                $rowData[] = $sheet->getCell($col . $row)->getValue();
            }
            $rows[] = $rowData;
        }

        return $rows;
    }

    protected function readCsv(string $filePath, ?int $limit = null): array
    {
        $rows = [];
        $handle = fopen($filePath, 'r');

        if ($handle === false) {
            throw new \RuntimeException("Could not open file: {$filePath}");
        }

        $count = 0;
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
            $count++;

            if ($limit && $count >= $limit) {
                break;
            }
        }

        fclose($handle);

        return $rows;
    }

    protected function countRows(ImportJob $importJob): int
    {
        $filePath = Storage::disk('local')->path($importJob->file_path);
        $extension = pathinfo($importJob->original_name, PATHINFO_EXTENSION);

        if (in_array(strtolower($extension), ['xlsx', 'xls'])) {
            $spreadsheet = IOFactory::load($filePath);
            return $spreadsheet->getActiveSheet()->getHighestRow() - 1; // Exclude header
        }

        // CSV
        $count = 0;
        $handle = fopen($filePath, 'r');
        while (fgetcsv($handle) !== false) {
            $count++;
        }
        fclose($handle);

        return max(0, $count - 1); // Exclude header
    }

    protected function suggestColumnMapping(array $headers, array $fields): array
    {
        $mapping = [];

        foreach ($headers as $index => $header) {
            $header = strtolower(trim($header));

            foreach ($fields as $field => $config) {
                $label = strtolower($config['label']);

                if ($header === $field || $header === $label || str_contains($label, $header) || str_contains($header, $field)) {
                    $mapping[$index] = $field;
                    break;
                }
            }
        }

        return $mapping;
    }

    protected function mapRowToData(array $row, array $headers, array $mapping, array $fields): array
    {
        $data = [];

        foreach ($mapping as $columnIndex => $fieldName) {
            $value = $row[$columnIndex] ?? null;

            // Type conversion
            $fieldConfig = $fields[$fieldName] ?? [];
            $data[$fieldName] = $this->convertValue($value, $fieldConfig);
        }

        return $data;
    }

    protected function convertValue($value, array $fieldConfig)
    {
        if ($value === null || $value === '') {
            return $fieldConfig['default'] ?? null;
        }

        $type = $fieldConfig['type'] ?? 'string';

        return match ($type) {
            'integer' => (int) $value,
            'decimal' => (float) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'date' => $this->parseDate($value),
            'email' => strtolower(trim($value)),
            default => trim((string) $value),
        };
    }

    protected function parseDate($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            if (is_numeric($value)) {
                // Excel serial date
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value)->format('Y-m-d');
            }

            return date('Y-m-d', strtotime($value));
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function validateRow(array $row, array $headers, array $mapping, array $fields): array
    {
        $errors = [];
        $data = $this->mapRowToData($row, $headers, $mapping, $fields);

        foreach ($fields as $field => $config) {
            $value = $data[$field] ?? null;

            if (($config['required'] ?? false) && ($value === null || $value === '')) {
                $errors[] = "{$config['label']} is required";
                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            // Type validation
            $type = $config['type'] ?? 'string';

            if ($type === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "{$config['label']} must be a valid email";
            }

            if ($type === 'integer' && !is_numeric($value)) {
                $errors[] = "{$config['label']} must be a number";
            }

            if ($type === 'decimal' && !is_numeric($value)) {
                $errors[] = "{$config['label']} must be a number";
            }
        }

        return $errors;
    }

    protected function isEmptyRow(array $data, array $requiredFields): bool
    {
        foreach ($requiredFields as $field) {
            if (!empty($data[$field])) {
                return false;
            }
        }

        return true;
    }

    protected function getSampleValue(string $field, array $config): string
    {
        $type = $config['type'] ?? 'string';

        return match ($field) {
            'email' => 'example@company.com',
            'phone' => '+966501234567',
            'company_name' => 'Sample Company Ltd',
            'contact_name' => 'John Doe',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'sku' => 'PROD-001',
            'name' => 'Sample Product',
            'code' => '1000',
            default => match ($type) {
                'integer' => '100',
                'decimal' => '1000.00',
                'boolean' => 'true',
                'date' => date('Y-m-d'),
                'email' => 'example@company.com',
                default => 'Sample Value',
            },
        };
    }

    protected function getImporter(string $entityType): ImporterInterface
    {
        if (!isset($this->importers[$entityType])) {
            $this->importers[$entityType] = match ($entityType) {
                ImportJob::ENTITY_CUSTOMERS, ImportJob::ENTITY_SUPPLIERS => new Importers\ContactImporter(),
                ImportJob::ENTITY_PRODUCTS => new Importers\ProductImporter(),
                ImportJob::ENTITY_EMPLOYEES => new Importers\EmployeeImporter(),
                ImportJob::ENTITY_CHART_OF_ACCOUNTS => new Importers\ChartOfAccountImporter(),
                ImportJob::ENTITY_LEADS => new Importers\LeadImporter(),
                default => throw new \InvalidArgumentException("No importer for: {$entityType}"),
            };
        }

        return $this->importers[$entityType];
    }
}
