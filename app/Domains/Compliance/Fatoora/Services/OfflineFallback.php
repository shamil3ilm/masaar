<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Domains\Compliance\Fatoora\Enums\ErrorCode;
use App\Domains\Compliance\Fatoora\Exceptions\FatooraException;
use App\Domains\Invoice\Models\Invoice;
use Illuminate\Support\Facades\Log;

/**
 * Offline-Aware Submission Service.
 *
 * Wraps the standard SubmissionTracker with offline mode detection.
 * Automatically queues invoices for later submission when:
 * - ZATCA API is unavailable
 * - Network connectivity issues detected
 * - Circuit breaker is open
 * - Force offline mode is enabled
 *
 * Use this service for POS/retail scenarios where invoices
 * must be issued regardless of connectivity.
 */
class OfflineFallback
{
    public function __construct(
        private readonly SubmissionTracker $submissionService,
        private readonly OfflineQueue $offlineQueueManager,
        private readonly Connectivity $connectivityChecker,
        private readonly DocumentBuilder $complianceService,
    ) {}

    /**
     * Submit invoice with automatic offline fallback.
     *
     * @param  Invoice  $invoice  Invoice to submit
     * @param  array  $options  Submission options
     * @return array Submission result
     */
    public function submit(Invoice $invoice, array $options = []): array
    {
        $forceOffline = $options['force_offline'] ?? false;
        $skipConnectivityCheck = $options['skip_connectivity_check'] ?? false;
        $async = $options['async'] ?? false;
        $idempotencyKey = $options['idempotency_key'] ?? null;

        // Check if offline mode should be used
        $useOffline = $forceOffline || (
            ! $skipConnectivityCheck && $this->connectivityChecker->shouldUseOfflineMode()
        );

        if ($useOffline) {
            return $this->queueForOffline($invoice, $options);
        }

        try {
            // Attempt normal submission
            return $this->submissionService->submit($invoice, $idempotencyKey, $async);

        } catch (FatooraException $e) {
            // Check if error is connectivity-related and should trigger offline mode
            if ($this->shouldFallbackToOffline($e)) {
                Log::warning('Submission failed, falling back to offline queue', [
                    'invoice_id' => $invoice->id,
                    'error_code' => $e->getErrorCode()->value,
                    'error' => $e->getMessage(),
                ]);

                return $this->queueForOffline($invoice, $options);
            }

            // Re-throw for non-connectivity errors
            throw $e;
        } catch (\Exception $e) {
            // Check for general connectivity issues
            if ($this->isConnectivityError($e)) {
                Log::warning('Connectivity error, falling back to offline queue', [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);

                return $this->queueForOffline($invoice, $options);
            }

            throw $e;
        }
    }

    /**
     * Queue invoice for offline submission.
     */
    private function queueForOffline(Invoice $invoice, array $options): array
    {
        $organization = $invoice->organization;

        // Generate signed XML if not already signed
        if (! $invoice->signed_xml || ! $invoice->hash || ! $invoice->qr_code) {
            $complianceData = $this->complianceService->generateComplianceData(
                invoice: $invoice,
                organization: $organization,
                previousInvoiceHash: $invoice->previous_invoice_hash,
                privateKey: $organization->zatca_private_key,
                certificate: $organization->zatca_certificate,
            );

            // Update invoice with signed data
            $invoice->update([
                'signed_xml' => $complianceData['xml'],
                'hash' => $complianceData['hash'],
                'qr_code' => $complianceData['qrCode'],
            ]);

            $signedXml = $complianceData['xml'];
            $invoiceHash = $complianceData['hash'];
            $qrCode = $complianceData['qrCode'];
        } else {
            $signedXml = $invoice->signed_xml;
            $invoiceHash = $invoice->hash;
            $qrCode = $invoice->qr_code;
        }

        // Determine priority
        $priority = $options['priority'] ?? $this->determinePriority($invoice);

        // Queue for later submission
        $queueResult = $this->offlineQueueManager->queue(
            invoice: $invoice,
            signedXml: $signedXml,
            invoiceHash: $invoiceHash,
            qrCode: $qrCode,
            priority: $priority
        );

        Log::info('Invoice queued for offline submission', [
            'invoice_id' => $invoice->id,
            'queue_id' => $queueResult['queue_id'],
            'position' => $queueResult['position'],
            'priority' => $priority,
        ]);

        return [
            'success' => true,
            'status' => 'queued_offline',
            'offline' => true,
            'queue_id' => $queueResult['queue_id'],
            'position' => $queueResult['position'],
            'estimated_wait_seconds' => $queueResult['estimated_wait'],
            'message' => 'Invoice queued for submission when connectivity is restored',
            'invoice' => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'hash' => $invoiceHash,
                'qr_code' => $qrCode,
            ],
        ];
    }

    /**
     * Check if exception should trigger offline fallback.
     */
    private function shouldFallbackToOffline(FatooraException $e): bool
    {
        $connectivityErrors = [
            ErrorCode::NET_CONNECTION_TIMEOUT,
            ErrorCode::NET_CONNECTION_REFUSED,
            ErrorCode::NET_DNS_RESOLUTION_FAILED,
            ErrorCode::ZATCA_SERVICE_UNAVAILABLE,
            ErrorCode::ZATCA_GATEWAY_TIMEOUT,
            ErrorCode::ZATCA_MAINTENANCE,
        ];

        return in_array($e->getErrorCode(), $connectivityErrors, true);
    }

    /**
     * Check if exception is a general connectivity error.
     */
    private function isConnectivityError(\Exception $e): bool
    {
        $message = strtolower($e->getMessage());

        $patterns = [
            'connection',
            'timeout',
            'refused',
            'dns',
            'resolve',
            'network',
            'unreachable',
            'curl error',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine submission priority based on invoice characteristics.
     */
    private function determinePriority(Invoice $invoice): string
    {
        // B2B invoices are higher priority (clearance required)
        if ($invoice->isB2B()) {
            return 'high';
        }

        // Large invoices get higher priority
        if ($invoice->total > 10000) {
            return 'high';
        }

        // Credit/debit notes are normal priority
        if ($invoice->document_type?->requiresBillingReference()) {
            return 'normal';
        }

        return 'normal';
    }

    /**
     * Get current offline queue status for organization.
     */
    public function getOfflineQueueStatus(string $organizationId): array
    {
        $queueStatus = $this->offlineQueueManager->getStatus($organizationId);
        $connectivity = $this->connectivityChecker->getDetailedStatus();

        return [
            'queue' => $queueStatus,
            'connectivity' => $connectivity,
            'mode' => $this->connectivityChecker->shouldUseOfflineMode() ? 'offline' : 'online',
        ];
    }

    /**
     * Check if submission can proceed online.
     */
    public function canSubmitOnline(): bool
    {
        return $this->connectivityChecker->isAvailable();
    }

    /**
     * Force connectivity refresh.
     */
    public function refreshConnectivity(): array
    {
        return $this->connectivityChecker->forceCheck();
    }
}
