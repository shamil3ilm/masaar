<?php

declare(strict_types=1);

namespace App\Domains\Platform\Services;

use App\Domains\Compliance\Countries\SaudiArabia\Filing\CircuitBreaker;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\Connectivity;
use App\Domains\Compliance\Countries\SaudiArabia\Filing\OfflineQueue;

/**
 * Turns the platform's health signals into a list of problems to act on.
 *
 * Each check stands alone and contributes at most one issue, in a fixed
 * order: reachability first, then what has piled up because of it.
 */
class IssueDetector
{
    /**
     * The thresholds are settings, not constants.
     *
     * They were private consts here - 100 queued invoices is a warning, 500
     * is critical, 50 failed items is critical, 10 rejections in a day is a
     * warning. The right value for any of them is only learned by watching
     * the platform run, and changing one meant editing this file, raising a
     * pull request and shipping a release.
     */
    public function __construct(
        private readonly Connectivity $connectivity,
        private readonly CircuitBreaker $circuitBreaker,
        private readonly PlatformStatus $status,
        private readonly QueueReport $queue,
        private readonly SubmissionLog $submissionLog,
        private readonly Settings $settings,
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

        if ($pending <= $this->settings->get('alerts.backlog_warning')) {
            return null;
        }

        return [
            'type' => 'offline_queue',
            'severity' => $pending > $this->settings->get('alerts.backlog_critical') ? 'critical' : 'warning',
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
            'severity' => $failed > $this->settings->get('alerts.failed_critical') ? 'critical' : 'warning',
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

        if ($rejections <= $this->settings->get('alerts.rejections_warning')) {
            return null;
        }

        return [
            'type' => 'rejections',
            'severity' => 'warning',
            'message' => "{$rejections} rejections in the last 24 hours",
        ];
    }
}
