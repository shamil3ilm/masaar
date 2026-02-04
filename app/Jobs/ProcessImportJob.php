<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Core\ImportJob;
use App\Services\Core\ImportService;
use App\Services\Core\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600; // 10 minutes max

    public function __construct(
        protected int $importJobId
    ) {}

    public function handle(ImportService $importService, NotificationService $notificationService): void
    {
        $importJob = ImportJob::with('user')->find($this->importJobId);

        if (!$importJob) {
            return;
        }

        if ($importJob->status !== ImportJob::STATUS_PENDING && $importJob->status !== ImportJob::STATUS_VALIDATING) {
            return;
        }

        try {
            $importJob = $importService->processImport($importJob);

            // Send notification to user
            if ($importJob->user) {
                $message = $importJob->isCompleted()
                    ? "Import completed: {$importJob->success_rows} rows imported successfully"
                    : "Import failed: {$importJob->failed_rows} errors";

                $notificationService->send(
                    $importJob->user,
                    'system.task_complete',
                    $importJob->isCompleted() ? 'Import Completed' : 'Import Failed',
                    $message,
                    null,
                    null,
                    null,
                    [
                        'import_id' => $importJob->uuid,
                        'entity_type' => $importJob->entity_type,
                        'success_rows' => $importJob->success_rows,
                        'failed_rows' => $importJob->failed_rows,
                    ]
                );
            }
        } catch (\Exception $e) {
            $importJob->markAsFailed($e->getMessage());

            if ($importJob->user) {
                $notificationService->send(
                    $importJob->user,
                    'system.alert',
                    'Import Failed',
                    "Import failed: {$e->getMessage()}",
                    null,
                    null,
                    null,
                    ['import_id' => $importJob->uuid, 'error' => $e->getMessage()]
                );
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        $importJob = ImportJob::find($this->importJobId);

        if ($importJob) {
            $importJob->markAsFailed($exception->getMessage());
        }
    }
}
