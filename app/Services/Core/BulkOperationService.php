<?php

declare(strict_types=1);

namespace App\Services\Core;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Service for handling bulk operations with proper error handling,
 * partial success tracking, and idempotency.
 */
class BulkOperationService
{
    /**
     * Execute bulk operation with all-or-nothing transaction.
     */
    public function executeAllOrNothing(
        array $items,
        Closure $operation,
        ?Closure $onProgress = null
    ): BulkOperationResult {
        $result = new BulkOperationResult();
        $result->total = count($items);

        try {
            DB::beginTransaction();

            foreach ($items as $index => $item) {
                $result->processed++;
                $operation($item, $index);
                $result->succeeded++;

                if ($onProgress) {
                    $onProgress($result);
                }
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            $result->failed = $result->total;
            $result->succeeded = 0;
            $result->errors[] = [
                'index' => $result->processed - 1,
                'error' => $e->getMessage(),
            ];

            Log::error('Bulk operation failed', [
                'processed' => $result->processed,
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Execute bulk operation with partial success (continue on error).
     */
    public function executeWithPartialSuccess(
        array $items,
        Closure $operation,
        bool $useTransactionPerItem = true,
        ?Closure $onProgress = null
    ): BulkOperationResult {
        $result = new BulkOperationResult();
        $result->total = count($items);

        foreach ($items as $index => $item) {
            $result->processed++;

            try {
                if ($useTransactionPerItem) {
                    DB::transaction(fn() => $operation($item, $index));
                } else {
                    $operation($item, $index);
                }

                $result->succeeded++;
                $result->successfulItems[] = $index;
            } catch (Throwable $e) {
                $result->failed++;
                $result->errors[] = [
                    'index' => $index,
                    'item' => $this->getItemIdentifier($item),
                    'error' => $e->getMessage(),
                ];

                Log::warning('Bulk operation item failed', [
                    'index' => $index,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($onProgress) {
                $onProgress($result);
            }
        }

        return $result;
    }

    /**
     * Execute bulk operation in batches.
     */
    public function executeInBatches(
        array $items,
        Closure $batchOperation,
        int $batchSize = 100,
        ?Closure $onBatchComplete = null
    ): BulkOperationResult {
        $result = new BulkOperationResult();
        $result->total = count($items);

        $batches = array_chunk($items, $batchSize);

        foreach ($batches as $batchIndex => $batch) {
            try {
                DB::transaction(function () use ($batch, $batchOperation, &$result) {
                    foreach ($batch as $item) {
                        $batchOperation($item);
                        $result->succeeded++;
                    }
                    $result->processed += count($batch);
                });
            } catch (Throwable $e) {
                $result->failed += count($batch);
                $result->processed += count($batch);
                $result->errors[] = [
                    'batch' => $batchIndex,
                    'error' => $e->getMessage(),
                    'items_affected' => count($batch),
                ];

                Log::error('Bulk operation batch failed', [
                    'batch' => $batchIndex,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($onBatchComplete) {
                $onBatchComplete($batchIndex, $result);
            }
        }

        return $result;
    }

    /**
     * Execute idempotent bulk operation (skip already processed).
     */
    public function executeIdempotent(
        array $items,
        Closure $operation,
        Closure $wasProcessed,
        ?Closure $onProgress = null
    ): BulkOperationResult {
        $result = new BulkOperationResult();
        $result->total = count($items);

        foreach ($items as $index => $item) {
            // Skip if already processed
            if ($wasProcessed($item)) {
                $result->skipped++;
                $result->processed++;
                continue;
            }

            try {
                DB::transaction(fn() => $operation($item, $index));
                $result->succeeded++;
            } catch (Throwable $e) {
                $result->failed++;
                $result->errors[] = [
                    'index' => $index,
                    'item' => $this->getItemIdentifier($item),
                    'error' => $e->getMessage(),
                ];
            }

            $result->processed++;

            if ($onProgress) {
                $onProgress($result);
            }
        }

        return $result;
    }

    /**
     * Get an identifier for an item (for error reporting).
     */
    protected function getItemIdentifier($item): mixed
    {
        if (is_object($item)) {
            return $item->id ?? $item->uuid ?? get_class($item);
        }

        if (is_array($item)) {
            return $item['id'] ?? $item['uuid'] ?? json_encode($item);
        }

        return $item;
    }
}

/**
 * Result of a bulk operation.
 */
class BulkOperationResult
{
    public int $total = 0;
    public int $processed = 0;
    public int $succeeded = 0;
    public int $failed = 0;
    public int $skipped = 0;
    public array $errors = [];
    public array $successfulItems = [];

    public function isComplete(): bool
    {
        return $this->processed >= $this->total;
    }

    public function isFullySuccessful(): bool
    {
        return $this->failed === 0 && $this->succeeded === $this->total;
    }

    public function hasErrors(): bool
    {
        return $this->failed > 0;
    }

    public function getSuccessRate(): float
    {
        if ($this->total === 0) {
            return 100.0;
        }

        return ($this->succeeded / $this->total) * 100;
    }

    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'processed' => $this->processed,
            'succeeded' => $this->succeeded,
            'failed' => $this->failed,
            'skipped' => $this->skipped,
            'success_rate' => round($this->getSuccessRate(), 2),
            'errors' => $this->errors,
        ];
    }
}
