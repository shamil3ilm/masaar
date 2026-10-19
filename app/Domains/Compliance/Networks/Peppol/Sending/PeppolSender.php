<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\Sending;

use App\Domains\Compliance\Networks\Peppol\Contracts\AspGateway;
use App\Domains\Compliance\Networks\Peppol\Exceptions\AspRefusedException;
use App\Domains\Compliance\Networks\Peppol\Exceptions\AspUnavailableException;
use App\Domains\Compliance\Networks\Peppol\Exceptions\NoAspConfiguredException;
use App\Domains\Compliance\Networks\Peppol\Jobs\SendPeppolDocument;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use Illuminate\Support\Facades\Log;

/**
 * Hands ready documents to the provider, each exactly once.
 *
 * A document is queued once (queueOnce, on queued_at): at issue, or on an
 * explicit send when the invoice was issued with auto_submit false.
 *
 * A document is claimed - ready to sending, under its row lock - before the
 * call and outside any transaction during it, so two workers cannot both
 * send it and no lock is held while the provider thinks. Once the provider
 * gives it a message id it is 'sent' and never claimed again; the unique
 * index on direction and asp_message_id backs that up.
 *
 * A document left in 'sending' by a worker that died mid-call may already be
 * with the provider. It is not sent again: the provider would file it twice.
 */
final class PeppolSender
{
    /** Attempts without a verdict before a document is given up on. */
    public const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly AspGateway $gateway,
        private readonly DocumentStatus $status,
    ) {}

    /**
     * Send a ready outbound document in the background, once its row is
     * committed - before that, the worker could not see it.
     */
    public function queue(PeppolDocument $document): void
    {
        SendPeppolDocument::dispatch((string) $document->id)->afterCommit();
    }

    /**
     * Queue a ready document that has never been queued.
     *
     * queued_at is claimed in one conditional update, so two callers - a
     * double-clicked send, a retried pipeline call - cannot both queue it.
     * A document already queued, or past ready, is left alone.
     *
     * @return bool whether this call queued it
     */
    public function queueOnce(PeppolDocument $document): bool
    {
        $claimed = PeppolDocument::withoutTenantScope(fn (): int => PeppolDocument::query()
            ->whereKey($document->id)
            ->where('direction', PeppolDocument::DIRECTION_OUT)
            ->where('status', DocumentStatus::READY)
            ->whereNull('queued_at')
            ->update(['queued_at' => now()])) === 1;

        if ($claimed) {
            $this->queue($document);
        }

        return $claimed;
    }

    /**
     * One attempt.
     *
     * @return bool whether to try again later
     */
    public function send(string $documentId): bool
    {
        $document = $this->status->transition(
            $documentId,
            DocumentStatus::SENDING,
            fn (PeppolDocument $locked) => ['attempts' => $locked->attempts + 1],
        );

        // Not ready: sent already, being sent, or settled.
        if ($document === null) {
            return false;
        }

        try {
            $messageId = $this->gateway->submit($document);
        } catch (NoAspConfiguredException) {
            // Not an attempt: the document waits, uncounted, for a manual upload.
            $this->status->transition($documentId, DocumentStatus::READY, fn (PeppolDocument $locked) => [
                'attempts' => max(0, $locked->attempts - 1),
            ]);

            return false;
        } catch (AspRefusedException $e) {
            $this->status->transition($documentId, DocumentStatus::REJECTED, [
                'errors' => $e->errors,
                'last_error' => $e->getMessage(),
            ]);

            Log::warning('Peppol document refused by the service provider', [
                'document_id' => $documentId,
                'error' => $e->getMessage(),
            ]);

            return false;
        } catch (AspUnavailableException $e) {
            $exhausted = $document->attempts >= self::MAX_ATTEMPTS;

            $this->status->transition($documentId, $exhausted ? DocumentStatus::FAILED : DocumentStatus::READY, [
                'last_error' => $e->getMessage(),
            ]);

            Log::warning('Peppol document not sent: no verdict from the service provider', [
                'document_id' => $documentId,
                'attempt' => $document->attempts,
                'will_retry' => ! $exhausted,
                'error' => $e->getMessage(),
            ]);

            return ! $exhausted;
        }

        // Taken: delivery and the report to the authority both start now,
        // in parallel (Electronic Invoicing Guidelines v1.1, p10 steps 3-4).
        $this->status->transition($documentId, DocumentStatus::SENT, [
            'asp_message_id' => $messageId,
            'last_error' => null,
            'reporting_status' => DocumentStatus::PENDING,
            'reporting_changed_at' => now(),
        ]);

        return false;
    }

    /**
     * Try a failed document again, from its first attempt.
     *
     * For an operator, once whatever failed every attempt - credentials, an
     * outage - is fixed. Safe to repeat: the document keeps its id, which is
     * the idempotency key the provider de-duplicates on.
     *
     * @return bool whether it was failed and is now queued
     */
    public function resend(string $documentId): bool
    {
        $document = $this->status->transition($documentId, DocumentStatus::READY, [
            'attempts' => 0,
            'last_error' => null,
        ], from: DocumentStatus::FAILED);

        if ($document === null) {
            return false;
        }

        $this->queue($document);

        return true;
    }

    /**
     * The queue stopped trying before the document's own attempts ran out.
     *
     * Only a document still waiting is marked failed. One in 'sending' may be
     * with the provider, so it is left alone, loudly.
     */
    public function giveUp(string $documentId, string $reason): void
    {
        $failed = $this->status->transition($documentId, DocumentStatus::FAILED, [
            'last_error' => $reason,
        ], from: DocumentStatus::READY);

        if ($failed !== null) {
            return;
        }

        $document = PeppolDocument::withoutTenantScope(fn () => PeppolDocument::query()->find($documentId));

        if ($document?->status === DocumentStatus::SENDING) {
            Log::critical('A Peppol document was being sent when its job gave up; check with the service provider before it is sent again', [
                'document_id' => $documentId,
                'org_id' => $document->org_id,
                'error' => $reason,
            ]);
        }
    }
}
