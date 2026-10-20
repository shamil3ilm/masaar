<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Filing;

use App\Domains\Compliance\Countries\SaudiArabia\Document\DocumentBuilder;
use App\Domains\Compliance\Countries\SaudiArabia\Exceptions\FatooraException;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraEnvironment;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CertificatePem;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CredentialStore;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\RevocationCheck;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\SigningCredentials;
use App\Domains\Invoice\Models\Invoice;
use App\Support\Errors\ComplianceException;
use App\Support\Errors\ErrorCode;
use Illuminate\Support\Facades\DB;
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
        private readonly CredentialStore $credentials,
        private readonly ReportingAttempts $attempts,
        private readonly RevocationCheck $revocation,
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

        } catch (ComplianceException $e) {
            // Check if error is connectivity-related and should trigger offline mode
            if ($this->shouldFallbackToOffline($e)) {
                Log::warning('Submission failed, falling back to offline queue', [
                    'invoice_id' => $invoice->id,
                    'error_code' => $e->getErrorCode()->value,
                    'error' => $e->getMessage(),
                ]);
                $this->attempts->record((string) $invoice->org_id, (string) $invoice->id, ReportingAttempts::UNREACHABLE, $e->getMessage());

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
                $this->attempts->record((string) $invoice->org_id, (string) $invoice->id, ReportingAttempts::UNREACHABLE, $e->getMessage());

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
        $organization = $invoice->org;

        // signing(), not get(): a branch holding no credentials of its own
        // falls back to the organization's certificate, which is what the
        // signing path has always done and this did not - so a branch invoice
        // was re-signed unsigned while a usable PCSID sat one level up.
        $signing = $this->credentials->signing((string) $organization->id, SigningCredentials::keyOf($invoice));

        // Only an invoice that was never issued is signed here.
        //
        // This used to read "no signed_xml OR no hash OR no qr_code". A Phase
        // 1 invoice issued without a certificate legitimately has signed_xml
        // null, so the guard fired on an invoice that was already issued,
        // already hashed and already recorded in hash_chain_history - and
        // overwrote invoices.hash with a freshly computed one. The next
        // invoice derives its PIH from invoices.hash
        // (Invoice::getPreviousInvoiceHashAttribute), so its recorded
        // predecessor and its derived predecessor stopped agreeing and
        // fatoora:verify-hash-chain reported a mismatch on a chain nobody had
        // touched. hash is in MUTABLE_AFTER_FINALIZED, so the immutability
        // guard did not stop it.
        //
        // Re-signing an issued document is not a repair in any case: the
        // signing timestamp is part of the document, so the hash necessarily
        // changes, and the remedy for a document that needs to change is a
        // credit note.
        // An issued document that was never signed cannot be filed at all.
        //
        // Signing it now would give it a different hash - the signing
        // timestamp is part of the document - and hash_chain_history already
        // holds the original, so the successor's recorded predecessor and its
        // derived one would stop agreeing. Re-signing is what the old guard
        // did here, silently. Refusing is the honest answer, and the remedy
        // is a credit note and a fresh invoice rather than a second version
        // of this one.
        if ($invoice->hash !== null && $invoice->signed_xml === null) {
            throw new FatooraException(
                "Invoice {$invoice->invoice_number} was issued without a signature and cannot be filed now. "
                .'Signing it would change the hash the chain already records. Issue a credit note and a new invoice instead.',
                ErrorCode::VAL_INVOICE_ALREADY_ISSUED,
                ['invoice_id' => $invoice->id, 'icv' => $invoice->icv],
            );
        }

        if ($invoice->hash === null) {
            // Checked before it stamps, as issuing does (Security Features
            // Implementation Standard v1.2, section 2.2.1, requirement 14).
            $stampWith = CertificatePem::from($signing['pcsid'] ?? null);

            if ($stampWith !== null) {
                $this->revocation->assertMayStamp($stampWith);
            }

            $complianceData = $this->complianceService->generateComplianceData(
                invoice: $invoice,
                organization: $organization,
                previousInvoiceHash: $invoice->previous_invoice_hash,
                // As in ProcessFatooraSubmission: these read model attributes
                // that do not exist, so both were null and the document was
                // filed unsigned.
                privateKey: $signing['privateKey'] ?? null,
                certificate: CertificatePem::from($signing['pcsid'] ?? null),
                environment: FatooraEnvironment::of($organization),
            );

            // Update invoice with signed data. The key is qr_code — this read
            // qrCode, which DocumentBuilder does not return, so the QR was null
            // both on the invoice and in the queue. For a B2C sale that QR is
            // what gets printed on the receipt, so an outage produced receipts
            // with nothing on them to scan.
            $signedXml = $complianceData['xml'];
            $invoiceHash = $complianceData['hash'];
            $qrCode = $complianceData['qr_code'];
        } else {
            $complianceData = null;
            $signedXml = $invoice->signed_xml;
            $invoiceHash = $invoice->hash;
            $qrCode = $invoice->qr_code;
        }

        // Determine priority
        $priority = $options['priority'] ?? $this->determinePriority($invoice);

        // Signing the invoice, recording the submission and queueing it are
        // one change.
        //
        // They were two writes with no transaction. Dying between them left an
        // invoice carrying a hash and a QR with no queue row, so nothing would
        // ever file it.
        //
        // The submission row is the third. This path wrote none, so an invoice
        // queued during an outage reserved no licence allowance and appeared
        // on no submissions screen - the portal and the admin logs both read
        // invoice_submissions. recordOffline reuses the row an online attempt
        // already created, so falling back does not charge the slot twice.
        $queueResult = DB::transaction(function () use ($invoice, $complianceData, $signedXml, $invoiceHash, $qrCode, $priority): array {
            if ($complianceData !== null) {
                $invoice->update([
                    'signed_xml' => $complianceData['xml'],
                    'hash' => $complianceData['hash'],
                    'qr_code' => $complianceData['qr_code'],
                ]);
            }

            $this->submissionService->recordOffline($invoice);

            return $this->offlineQueueManager->queue(
                invoice: $invoice,
                signedXml: $signedXml,
                invoiceHash: $invoiceHash,
                qrCode: $qrCode,
                priority: $priority
            );
        });

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
    private function shouldFallbackToOffline(ComplianceException $e): bool
    {
        // Three of these named cases that do not exist —
        // NET_CONNECTION_TIMEOUT, NET_CONNECTION_REFUSED and
        // ZATCA_GATEWAY_TIMEOUT — and an undefined enum case is a fatal Error,
        // not a null. So this method died the moment a FatooraException reached
        // it, and the invoice was neither submitted nor queued. The fallback
        // failed in exactly the outage it exists for.
        //
        // Not ErrorCode::isRetryable(): that also covers rate limiting, OCSP
        // and TSA failures, which are worth retrying but are not ZATCA being
        // unreachable. Offline mode is for the latter.
        $connectivityErrors = [
            ErrorCode::NET_CONNECTION_FAILED,
            ErrorCode::NET_TIMEOUT,
            ErrorCode::NET_DNS_RESOLUTION_FAILED,
            ErrorCode::NET_HOST_UNREACHABLE,
            ErrorCode::ZATCA_SERVICE_UNAVAILABLE,
            ErrorCode::ZATCA_TIMEOUT,
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
     * Force connectivity refresh.
     */
    public function refreshConnectivity(): array
    {
        return $this->connectivityChecker->forceCheck();
    }
}
