<?php

declare(strict_types=1);

namespace App\Domains\Platform\Services;

use App\Domains\Compliance\Fatoora\Services\CircuitBreaker;
use App\Domains\Compliance\Fatoora\Services\Connectivity;
use App\Domains\Compliance\Fatoora\Services\OfflineQueue;

/**
 * Turns the platform's health signals into a list of problems to act on.
 *
 * Each check stands alone and contributes at most one issue, in a fixed
 * order: reachability first, then what has piled up because of it.
 */
class IssueDetector
{
    private const BACKLOG_WARNING = 100;

    private const BACKLOG_CRITICAL = 500;

    private const FAILED_CRITICAL = 50;

    private const REJECTIONS_WARNING = 10;

    public function __construct(
        private readonly Connectivity $connectivity,
        private readonly CircuitBreaker $circuitBreaker,
        private readonly PlatformStatus $status,
        private readonly QueueReport $queue,
        private readonly SubmissionLog $submissionLog,
    ) {}

    /**
     * @return list<array{type: string, severity: string, message: string, details?: ?string}>
     */
    public function detect(): array
    {
        return array_values(array_filter([
            $this->unreachable(),
            $this->openCircuit(),
            $this->backlog(),
            $this->failedItems(),
            $this->expiringCertificates(),
            $this->rejections(),
        ]));
    }

    private function unreachable(): ?array
    {
        $connectivity = $this->connectivity->check();

        if ($connectivity['available']) {
            return null;
        }

        return [
            'type' => 'connectivity',
            'severity' => 'critical',
            'message' => 'ZATCA API is unavailable',
            'details' => $connectivity['reason'],
        ];
    }

    private function openCircuit(): ?array
    {
        if ($this->circuitBreaker->getState('zatca_api') !== CircuitBreaker::STATE_OPEN) {
            return null;
        }

        return [
            'type' => 'circuit_breaker',
            'severity' => 'critical',
            'message' => 'Circuit breaker is open due to repeated failures',
        ];
    }

    private function backlog(): ?array
    {
        $pending = $this->queue->countInState(OfflineQueue::STATE_PENDING);

        if ($pending <= self::BACKLOG_WARNING) {
            return null;
        }

        return [
            'type' => 'offline_queue',
            'severity' => $pending > self::BACKLOG_CRITICAL ? 'critical' : 'warning',
            'message' => "Offline queue has {$pending} pending items",
        ];
    }

    private function failedItems(): ?array
    {
        $failed = $this->queue->countInState(OfflineQueue::STATE_FAILED);

        if ($failed === 0) {
            return null;
        }

        return [
            'type' => 'failed_submissions',
            'severity' => $failed > self::FAILED_CRITICAL ? 'critical' : 'warning',
            'message' => "{$failed} failed items in offline queue",
        ];
    }

    private function expiringCertificates(): ?array
    {
        $expiring = $this->status->organizationsWithCertificate()
            ->filter(fn (array $details) => in_array($details['status'], ['critical', 'expired'], true))
            ->count();

        if ($expiring === 0) {
            return null;
        }

        return [
            'type' => 'certificate_expiry',
            'severity' => 'critical',
            'message' => "{$expiring} certificate(s) expiring within 7 days",
        ];
    }

    private function rejections(): ?array
    {
        $rejections = $this->submissionLog->rejectionsSince(now()->subHours(24));

        if ($rejections <= self::REJECTIONS_WARNING) {
            return null;
        }

        return [
            'type' => 'rejections',
            'severity' => 'warning',
            'message' => "{$rejections} rejections in the last 24 hours",
        ];
    }
}
