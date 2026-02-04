<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Core;

use App\Http\Controllers\Controller;
use App\Models\Core\ExportJob;
use App\Services\Core\ExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ExportController extends Controller
{
    public function __construct(
        protected ExportService $exportService
    ) {}

    /**
     * Get available export entity types.
     */
    public function entityTypes(): JsonResponse
    {
        $types = ExportJob::getEntityTypes();

        $result = [];
        foreach ($types as $code => $config) {
            $result[] = [
                'code' => $code,
                'name' => $config['name'],
                'module' => $config['module'],
                'columns' => $config['columns'],
            ];
        }

        return response()->json(['data' => $result]);
    }

    /**
     * Create an export job.
     */
    public function create(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'entity_type' => 'required|string',
            'format' => 'sometimes|string|in:xlsx,csv,pdf',
            'filters' => 'sometimes|array',
            'columns' => 'sometimes|array',
            'columns.*' => 'string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $entityType = $request->get('entity_type');

        // Validate entity type exists
        $types = ExportJob::getEntityTypes();
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
            $exportJob = $this->exportService->createExport(
                $entityType,
                $user,
                $request->get('format', 'xlsx'),
                $request->get('filters'),
                $request->get('columns')
            );

            // Process immediately (could be queued for large exports)
            $exportJob = $this->exportService->processExport($exportJob);

            return response()->json([
                'data' => $this->exportService->getStatus($exportJob),
                'message' => $exportJob->isReady()
                    ? "Export completed: {$exportJob->total_records} records"
                    : 'Export is being processed',
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Get export status.
     */
    public function status(Request $request, string $uuid): JsonResponse
    {
        $user = $request->user();

        $exportJob = ExportJob::where('uuid', $uuid)
            ->where('organization_id', $user->organization_id)
            ->firstOrFail();

        return response()->json([
            'data' => $this->exportService->getStatus($exportJob),
        ]);
    }

    /**
     * Download export file.
     */
    public function download(Request $request, string $uuid): mixed
    {
        $user = $request->user();

        $exportJob = ExportJob::where('uuid', $uuid)
            ->where('organization_id', $user->organization_id)
            ->firstOrFail();

        if (!$exportJob->isReady()) {
            if ($exportJob->isExpired()) {
                return response()->json(['error' => 'Export has expired'], 410);
            }

            if ($exportJob->status === ExportJob::STATUS_FAILED) {
                return response()->json(['error' => 'Export failed'], 400);
            }

            return response()->json(['error' => 'Export is not ready for download'], 400);
        }

        $filePath = $this->exportService->download($exportJob);

        if (!$filePath) {
            return response()->json(['error' => 'File not found'], 404);
        }

        $mimeType = match ($exportJob->format) {
            'csv' => 'text/csv',
            'pdf' => 'application/pdf',
            default => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };

        return response()->download($filePath, $exportJob->file_name, [
            'Content-Type' => $mimeType,
        ]);
    }

    /**
     * Get export history.
     */
    public function history(Request $request): JsonResponse
    {
        $user = $request->user();
        $entityType = $request->get('entity_type');
        $limit = min((int) $request->get('limit', 20), 100);

        $exports = $this->exportService->getHistory(
            $user->organization_id,
            $entityType,
            $limit
        );

        return response()->json([
            'data' => $exports->map(fn ($export) => [
                'id' => $export->id,
                'uuid' => $export->uuid,
                'entity_type' => $export->entity_type,
                'format' => $export->format,
                'status' => $export->status,
                'total_records' => $export->total_records,
                'file_name' => $export->file_name,
                'file_size' => $export->file_size,
                'is_ready' => $export->isReady(),
                'is_expired' => $export->isExpired(),
                'download_url' => $export->getDownloadUrl(),
                'created_at' => $export->created_at->toIso8601String(),
                'expires_at' => $export->expires_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Quick export endpoint - creates and returns download immediately.
     */
    public function quickExport(Request $request): mixed
    {
        $validator = Validator::make($request->all(), [
            'entity_type' => 'required|string',
            'format' => 'sometimes|string|in:xlsx,csv',
            'filters' => 'sometimes|array',
            'columns' => 'sometimes|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $entityType = $request->get('entity_type');

        // Validate entity type
        $types = ExportJob::getEntityTypes();
        if (!isset($types[$entityType])) {
            return response()->json(['error' => 'Invalid entity type'], 400);
        }

        try {
            $exportJob = $this->exportService->createExport(
                $entityType,
                $user,
                $request->get('format', 'xlsx'),
                $request->get('filters'),
                $request->get('columns')
            );

            $exportJob = $this->exportService->processExport($exportJob);

            if (!$exportJob->isReady()) {
                return response()->json(['error' => 'Export failed'], 400);
            }

            $filePath = $this->exportService->download($exportJob);

            $mimeType = match ($exportJob->format) {
                'csv' => 'text/csv',
                default => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            };

            return response()->download($filePath, $exportJob->file_name, [
                'Content-Type' => $mimeType,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }
}
