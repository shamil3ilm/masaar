<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Core;

use App\Http\Controllers\Controller;
use App\Models\Core\ImportJob;
use App\Services\Core\ImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ImportController extends Controller
{
    public function __construct(
        protected ImportService $importService
    ) {}

    /**
     * Get available import entity types.
     */
    public function entityTypes(): JsonResponse
    {
        $types = ImportJob::getEntityTypes();

        $result = [];
        foreach ($types as $code => $config) {
            $result[] = [
                'code' => $code,
                'name' => $config['name'],
                'module' => $config['module'],
                'required_fields' => $config['required_fields'],
                'fields' => $config['fields'],
            ];
        }

        return response()->json(['data' => $result]);
    }

    /**
     * Upload a file for import.
     */
    public function upload(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240', // 10MB max
            'entity_type' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $entityType = $request->get('entity_type');

        // Validate entity type exists
        $types = ImportJob::getEntityTypes();
        if (!isset($types[$entityType])) {
            return response()->json(['error' => 'Invalid entity type'], 400);
        }

        // Check module access
        $moduleService = app(\App\Services\Core\ModuleService::class);
        $module = $types[$entityType]['module'];
        if (!$moduleService->isModuleEnabled($user->organization_id, $module)) {
            return response()->json(['error' => "Module '{$module}' is not enabled"], 403);
        }

        try {
            $importJob = $this->importService->uploadFile(
                $request->file('file'),
                $entityType,
                $user
            );

            return response()->json([
                'data' => [
                    'id' => $importJob->id,
                    'uuid' => $importJob->uuid,
                    'entity_type' => $importJob->entity_type,
                    'file_name' => $importJob->original_name,
                    'status' => $importJob->status,
                ],
                'message' => 'File uploaded successfully. Preview and map columns before processing.',
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Preview uploaded file and get column mapping suggestions.
     */
    public function preview(Request $request, string $uuid): JsonResponse
    {
        $user = $request->user();

        $importJob = ImportJob::where('uuid', $uuid)
            ->where('organization_id', $user->organization_id)
            ->firstOrFail();

        if ($importJob->status !== ImportJob::STATUS_PENDING) {
            return response()->json(['error' => 'Import has already been processed'], 400);
        }

        try {
            $preview = $this->importService->previewFile($importJob);

            return response()->json(['data' => $preview]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Set column mapping and options for import.
     */
    public function configure(Request $request, string $uuid): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'column_mapping' => 'required|array',
            'options' => 'sometimes|array',
            'options.update_existing' => 'sometimes|boolean',
            'options.skip_errors' => 'sometimes|boolean',
            'options.dry_run' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();

        $importJob = ImportJob::where('uuid', $uuid)
            ->where('organization_id', $user->organization_id)
            ->firstOrFail();

        if ($importJob->status !== ImportJob::STATUS_PENDING) {
            return response()->json(['error' => 'Import has already been processed'], 400);
        }

        $importJob->update([
            'column_mapping' => $request->get('column_mapping'),
            'options' => array_merge($importJob->options ?? [], $request->get('options', [])),
        ]);

        // Validate the configuration
        $validation = $this->importService->validateImport($importJob);

        return response()->json([
            'data' => [
                'import_id' => $importJob->uuid,
                'validation' => $validation,
            ],
        ]);
    }

    /**
     * Start processing the import.
     */
    public function process(Request $request, string $uuid): JsonResponse
    {
        $user = $request->user();

        $importJob = ImportJob::where('uuid', $uuid)
            ->where('organization_id', $user->organization_id)
            ->firstOrFail();

        if (!in_array($importJob->status, [ImportJob::STATUS_PENDING, ImportJob::STATUS_VALIDATING])) {
            return response()->json(['error' => 'Import cannot be processed in current state'], 400);
        }

        if (!$importJob->column_mapping) {
            return response()->json(['error' => 'Column mapping is required. Call /configure first.'], 400);
        }

        try {
            // Process synchronously for now (could be queued for large imports)
            $importJob = $this->importService->processImport($importJob);

            return response()->json([
                'data' => $this->importService->getStatus($importJob),
                'message' => $importJob->isCompleted()
                    ? "Import completed: {$importJob->success_rows} succeeded, {$importJob->failed_rows} failed"
                    : 'Import processing',
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Get import status.
     */
    public function status(Request $request, string $uuid): JsonResponse
    {
        $user = $request->user();

        $importJob = ImportJob::where('uuid', $uuid)
            ->where('organization_id', $user->organization_id)
            ->firstOrFail();

        return response()->json([
            'data' => $this->importService->getStatus($importJob),
        ]);
    }

    /**
     * Cancel an import.
     */
    public function cancel(Request $request, string $uuid): JsonResponse
    {
        $user = $request->user();

        $importJob = ImportJob::where('uuid', $uuid)
            ->where('organization_id', $user->organization_id)
            ->firstOrFail();

        if ($this->importService->cancelImport($importJob)) {
            return response()->json(['message' => 'Import cancelled successfully']);
        }

        return response()->json(['error' => 'Cannot cancel import in current state'], 400);
    }

    /**
     * Get import history.
     */
    public function history(Request $request): JsonResponse
    {
        $user = $request->user();
        $entityType = $request->get('entity_type');
        $limit = min((int) $request->get('limit', 20), 100);

        $imports = $this->importService->getHistory(
            $user->organization_id,
            $entityType,
            $limit
        );

        return response()->json([
            'data' => $imports->map(fn ($import) => [
                'id' => $import->id,
                'uuid' => $import->uuid,
                'entity_type' => $import->entity_type,
                'file_name' => $import->original_name,
                'status' => $import->status,
                'total_rows' => $import->total_rows,
                'success_rows' => $import->success_rows,
                'failed_rows' => $import->failed_rows,
                'created_at' => $import->created_at->toIso8601String(),
                'completed_at' => $import->completed_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Download sample import template.
     */
    public function sampleTemplate(Request $request, string $entityType): mixed
    {
        $types = ImportJob::getEntityTypes();
        if (!isset($types[$entityType])) {
            return response()->json(['error' => 'Invalid entity type'], 400);
        }

        try {
            $filePath = $this->importService->generateSampleFile($entityType);
            $fullPath = Storage::disk('local')->path($filePath);

            return response()->download($fullPath, "{$entityType}_import_template.xlsx")->deleteFileAfterSend();
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Get saved import templates.
     */
    public function templates(Request $request): JsonResponse
    {
        $user = $request->user();
        $entityType = $request->get('entity_type');

        $templates = $this->importService->getTemplates($user->organization_id, $entityType);

        return response()->json([
            'data' => $templates->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'entity_type' => $t->entity_type,
                'column_mapping' => $t->column_mapping,
                'options' => $t->options,
                'is_default' => $t->is_default,
            ]),
        ]);
    }

    /**
     * Save an import template.
     */
    public function saveTemplate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'entity_type' => 'required|string',
            'column_mapping' => 'required|array',
            'options' => 'sometimes|array',
            'is_default' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();

        try {
            $template = $this->importService->saveTemplate(
                $user->organization_id,
                $request->get('name'),
                $request->get('entity_type'),
                $request->get('column_mapping'),
                $request->get('options', []),
                $request->get('is_default', false)
            );

            return response()->json([
                'data' => [
                    'id' => $template->id,
                    'name' => $template->name,
                    'entity_type' => $template->entity_type,
                ],
                'message' => 'Template saved successfully',
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }
}
