<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Domains\Compliance\Fatoora\Config\FatooraConfig;
use App\Domains\Compliance\Fatoora\Enums\ErrorCode;
use App\Domains\Compliance\Fatoora\Exceptions\FatooraException;
use App\Domains\Compliance\Fatoora\Models\ChainEntry;
use App\Domains\Compliance\Fatoora\Models\ChainState;
use App\Domains\Compliance\Fatoora\Models\InvoiceSubmission;
use App\Domains\Compliance\Fatoora\Models\OfflineItem;
use App\Domains\Invoice\Models\Invoice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Offline Queue Manager for ZATCA Submissions.
 *
 * Handles offline/contingency mode for:
 * - POS systems that cannot wait for ZATCA response
 * - Network instability scenarios
 * - ZATCA maintenance windows
 *
 * This addresses:
 * - Real-time clearance bottlenecks
 * - POS must not wait for ZATCA response
 * - Offline contingency mode
 * - Graceful degradation
 */
class OfflineQueue
{
    /**
     * Queue states.
     */
    public const STATE_PENDING = 'pending';

    public const STATE_PROCESSING = 'processing';

    public const STATE_COMPLETED = 'completed';

    public const STATE_FAILED = 'failed';

    /**
     * Cache key prefix for queue items.
     */
    private const CACHE_PREFIX = 'zatca:offline_queue:';

    /**
     * Max queue size per organization.
     */
    private int $maxQueueSize;

    public function __construct(
        private readonly KillSwitch $killSwitchManager,
        private readonly CredentialStore $credentials,
    ) {
        $this->maxQueueSize = FatooraConfig::getOfflineQueueMaxSize();
    }

    /**
     * Get max retry attempts from config.
     */
    private function getMaxAttempts(): int
    {
        return (int) config('fatoora.offline.max_attempts', 3);
    }

    /**
     * Queue an invoice for later submission.
     */
    public function queue(
        Invoice $invoice,
        string $signedXml,
        string $invoiceHash,
        string $qrCode,
        ?string $priority = 'normal'
    ): array {
        $organizationId = $invoice->org_id;

        // Check queue size limit
        $currentSize = $this->getQueueSize($organizationId);
        if ($currentSize >= $this->maxQueueSize) {
            throw new FatooraException(
                'Offline queue is full',
                ErrorCode::RATE_QUOTA_EXCEEDED,
                ['current_size' => $currentSize, 'max_size' => $this->maxQueueSize]
            );
        }

        $item = OfflineItem::create([
            'invoice_id' => $invoice->id,
            'org_id' => $organizationId,
            'signed_xml' => $signedXml,
            'invoice_hash' => $invoiceHash,
            'qr_code' => $qrCode,
            'state' => self::STATE_PENDING,
            'priority' => $priority,
            'attempts' => 0,
            'max_attempts' => $this->getMaxAttempts(),
            'queued_at' => now(),
            'next_attempt_at' => now(),
        ]);

        Log::info('Invoice queued for offline submission', [
            'queue_id' => $item->id,
            'invoice_id' => $invoice->id,
            'org_id' => $organizationId,
            'queue_size' => $currentSize + 1,
        ]);

        return [
            'queued' => true,
            'queue_id' => $item->id,
            'position' => $currentSize + 1,
            'estimated_wait' => $this->estimateWaitTime($organizationId),
        ];
    }

    /**
     * Get next items to process from queue.
     */
    public function getNextBatch(string $organizationId, int $limit = 10): array
    {
        // Don't process if kill switch is enabled
        if ($this->killSwitchManager->isSubmissionBlocked($organizationId)) {
            return [];
        }

        return OfflineItem::query()
            ->where('org_id', $organizationId)
            ->where('state', self::STATE_PENDING)
            ->where('next_attempt_at', '<=', now())
            ->orderBy('priority', 'desc')
            ->orderBy('queued_at', 'asc')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * Mark item as processing.
     */
    public function markProcessing(string $queueId): void
    {
        OfflineItem::query()->whereKey($queueId)->update([
            'state' => self::STATE_PROCESSING,
            'started_at' => now(),
        ]);
    }

    /**
     * Mark item as completed.
     */
    public function markCompleted(string $queueId, array $zatcaResponse): void
    {
        OfflineItem::query()->whereKey($queueId)->update([
            'state' => self::STATE_COMPLETED,
            // The model casts this column to array, so it encodes itself.
            'zatca_response' => $zatcaResponse,
            'completed_at' => now(),
        ]);

        Log::info('Offline queue item completed', [
            'queue_id' => $queueId,
            'zatca_uuid' => $zatcaResponse['invoiceUuid'] ?? null,
        ]);
    }

    /**
     * Mark item as failed with retry scheduling.
     */
    public function markFailed(string $queueId, string $error, bool $canRetry = true): void
    {
        $item = OfflineItem::find($queueId);

        if (! $item) {
            return;
        }

        $attempts = $item->attempts + 1;
        $maxAttempts = $item->max_attempts;

        if ($canRetry && $attempts < $maxAttempts) {
            // Schedule retry with exponential backoff
            $retryDelay = pow(2, $attempts) * 60; // 2, 4, 8 minutes...

            $item->update([
                'state' => self::STATE_PENDING,
                'attempts' => $attempts,
                'next_attempt_at' => now()->addSeconds($retryDelay),
                'last_error' => $error,
            ]);

            Log::warning('Offline queue item failed, scheduled for retry', [
                'queue_id' => $queueId,
                'attempt' => $attempts,
                'next_attempt_at' => now()->addSeconds($retryDelay)->toIso8601String(),
                'error' => $error,
            ]);
        } else {
            // Permanently failed
            $item->update([
                'state' => self::STATE_FAILED,
                'attempts' => $attempts,
                'last_error' => $error,
                'failed_at' => now(),
            ]);

            Log::error('Offline queue item permanently failed', [
                'queue_id' => $queueId,
                'attempts' => $attempts,
                'error' => $error,
            ]);
        }
    }

    /**
     * Get queue size for organization.
     */
    public function getQueueSize(string $organizationId): int
    {
        return OfflineItem::query()
            ->where('org_id', $organizationId)
            ->whereIn('state', [self::STATE_PENDING, self::STATE_PROCESSING])
            ->count();
    }

    /**
     * Estimate wait time in seconds.
     */
    public function estimateWaitTime(string $organizationId): int
    {
        $queueSize = $this->getQueueSize($organizationId);

        // Assume ~5 seconds per invoice processing
        return $queueSize * 5;
    }

    /**
     * Get queue status for organization.
     */
    public function getStatus(string $organizationId): array
    {
        $counts = OfflineItem::query()
            ->where('org_id', $organizationId)
            ->selectRaw('state, count(*) as count')
            ->groupBy('state')
            ->pluck('count', 'state')
            ->toArray();

        $oldestPending = OfflineItem::query()
            ->where('org_id', $organizationId)
            ->where('state', self::STATE_PENDING)
            ->orderBy('queued_at', 'asc')
            ->value('queued_at');

        return [
            'org_id' => $organizationId,
            'pending' => $counts[self::STATE_PENDING] ?? 0,
            'processing' => $counts[self::STATE_PROCESSING] ?? 0,
            'completed' => $counts[self::STATE_COMPLETED] ?? 0,
            'failed' => $counts[self::STATE_FAILED] ?? 0,
            'total_active' => ($counts[self::STATE_PENDING] ?? 0) + ($counts[self::STATE_PROCESSING] ?? 0),
            'oldest_pending_at' => $oldestPending,
            'estimated_wait_seconds' => $this->estimateWaitTime($organizationId),
            'is_accepting' => $this->getQueueSize($organizationId) < $this->maxQueueSize,
        ];
    }

    /**
     * Get item by ID.
     */
    public function getItem(string $queueId): ?object
    {
        return OfflineItem::find($queueId);
    }

    /**
     * Get items for invoice.
     */
    public function getItemsForInvoice(string $invoiceId): array
    {
        return OfflineItem::query()
            ->where('invoice_id', $invoiceId)
            ->orderBy('queued_at', 'desc')
            ->get()
            ->all();
    }

    /**
     * Cancel pending item.
     */
    public function cancel(string $queueId, ?string $reason = null): bool
    {
        $item = OfflineItem::find($queueId);

        if (! $item || $item->state !== self::STATE_PENDING) {
            return false;
        }

        $item->update([
            'state' => 'cancelled',
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
        ]);

        Log::info('Offline queue item cancelled', [
            'queue_id' => $queueId,
            'reason' => $reason,
        ]);

        return true;
    }

    /**
     * Clean up old completed/failed items.
     */
    public function cleanup(int $olderThanDays = 7): int
    {
        $deleted = OfflineItem::query()
            ->whereIn('state', [self::STATE_COMPLETED, self::STATE_FAILED, 'cancelled'])
            ->where('updated_at', '<', now()->subDays($olderThanDays))
            ->delete();

        if ($deleted > 0) {
            Log::info('Cleaned up offline queue', [
                'deleted_count' => $deleted,
                'older_than_days' => $olderThanDays,
            ]);
        }

        return $deleted;
    }

    /**
     * Check if system should go to offline mode.
     */
    public function shouldGoOffline(string $organizationId): bool
    {
        // Check if kill switch forces offline mode
        if ($this->killSwitchManager->isOfflineModeForced($organizationId)) {
            return true;
        }

        // Check circuit breaker status (injected from outside)
        // This method is meant to be called by orchestrator with circuit breaker info

        return false;
    }

    /**
     * Validate queued item before processing.
     * Ensures ICV hasn't been used and certificate is still valid.
     *
     * CRITICAL: Prevents duplicate ICV submission when queue replays.
     *
     * @return array{valid: bool, reason: ?string, action: string}
     */
    public function validateQueuedItem(object $item): array
    {
        // Check if this invoice was already submitted successfully
        // 'status' is not a column on invoice_submissions and 'accepted' is
        // not one of its states, so this threw before judging anything and the
        // queue never processed an item.
        $existingSubmission = InvoiceSubmission::query()
            ->where('invoice_id', $item->invoice_id)
            ->whereIn('state', ['cleared', 'reported'])
            ->first();

        if ($existingSubmission) {
            return [
                'valid' => false,
                'reason' => 'Invoice already submitted successfully',
                'action' => 'skip',
                'existing_submission_id' => $existingSubmission->id,
            ];
        }

        // Check if ICV was already used (race condition protection)
        $invoice = Invoice::find($item->invoice_id);
        if ($invoice && $invoice->icv) {
            $icvConflict = ChainEntry::query()
                ->where('org_id', $item->org_id)
                ->where('icv', $invoice->icv)
                ->where('invoice_id', '!=', $item->invoice_id)
                ->exists();

            if ($icvConflict) {
                return [
                    'valid' => false,
                    'reason' => 'ICV already used by another invoice',
                    'action' => 'regenerate_icv',
                    'current_icv' => $invoice->icv,
                ];
            }
        }

        // The certificate the organization signs with, read from the
        // credential store — where onboarding writes. An item judged to have
        // no certificate stays in the queue, so this has to look where the
        // certificate actually is.
        $certificate = $this->credentials->certificate(
            (string) $item->org_id,
            $item->branch_id ?? null
        );

        if ($certificate === null) {
            return [
                'valid' => false,
                'reason' => 'No active certificate found',
                'action' => 'resign',
            ];
        }

        // Check if hash in queue matches current chain
        $currentState = ChainState::query()
            ->where('org_id', $item->org_id)
            ->first();

        if ($currentState && $invoice && $invoice->hash) {
            // The PIH in the queued invoice should match what was current at queue time
            // If chain has advanced, we may need to re-sign
            Log::debug('Validating queued item hash chain', [
                'queue_id' => $item->id,
                'queued_hash' => substr($item->invoice_hash, 0, 16).'...',
                'current_chain_hash' => substr($currentState->last_hash, 0, 16).'...',
            ]);
        }

        return [
            'valid' => true,
            'reason' => null,
            'action' => 'process',
        ];
    }

    /**
     * Handle certificate rotation for queued items.
     * If certificate changed while item was queued, mark for re-signing.
     */
    public function handleCertificateRotation(string $organizationId): array
    {
        $affected = OfflineItem::query()
            ->where('org_id', $organizationId)
            ->where('state', self::STATE_PENDING)
            ->get();

        $results = ['marked_for_resign' => 0, 'already_valid' => 0];

        foreach ($affected as $item) {
            $validation = $this->validateQueuedItem($item);

            if ($validation['action'] === 'resign') {
                // Mark item as needing re-signature
                $item->update([
                    'last_error' => 'Certificate rotated - needs re-signing',
                ]);
                $results['marked_for_resign']++;
            } else {
                $results['already_valid']++;
            }
        }

        Log::info('Certificate rotation handling for offline queue', array_merge(
            ['org_id' => $organizationId],
            $results
        ));

        return $results;
    }

    /**
     * Get queue items that need re-signing due to certificate change.
     */
    public function getItemsNeedingResign(string $organizationId): array
    {
        return OfflineItem::query()
            ->where('org_id', $organizationId)
            ->where('state', self::STATE_PENDING)
            ->where('last_error', 'LIKE', '%needs re-signing%')
            ->get()
            ->all();
    }
}
