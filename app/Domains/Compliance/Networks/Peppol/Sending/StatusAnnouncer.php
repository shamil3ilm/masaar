<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\Sending;

use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use App\Domains\Compliance\Networks\Peppol\Receiving\ReceivedReader;
use App\Domains\Webhook\Services\WebhookService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells the organization when one of its e-invoices moves: its webhooks,
 * and the log for the moves an operator must act on.
 *
 * The taxpayer must hear, without undue delay, whether each document was
 * delivered and whether its tax data reached the authority (the Electronic
 * Invoicing Guidelines v1.1, p41 items iii and vi; p48), so each track's
 * outcomes are an event of their own. A failed report is logged as an
 * error as well: it is the platform's to retry (ReportRetrier) and an
 * operator's to watch.
 *
 * The invoice number is read from the document itself, so this knows
 * nothing of the invoice it was built from.
 */
final class StatusAnnouncer
{
    /** Event per track and status; a move not listed is not announced. */
    private const EVENTS = [
        DocumentStatus::TRACK_DELIVERY => [
            DocumentStatus::SENT => 'e_invoice.sent',
            // Taken for a report-only endpoint: sent, and nothing to deliver.
            DocumentStatus::NOT_EXCHANGED => 'e_invoice.sent',
            DocumentStatus::DELIVERED => 'e_invoice.delivered',
            DocumentStatus::REJECTED => 'e_invoice.rejected',
            DocumentStatus::FAILED => 'e_invoice.failed',
        ],
        DocumentStatus::TRACK_REPORTING => [
            DocumentStatus::REPORTED => 'e_invoice.reported',
            DocumentStatus::REPORT_FAILED => 'e_invoice.report_failed',
        ],
    ];

    public const RECEIVED = 'e_invoice.received';

    public function __construct(
        private readonly WebhookService $webhooks,
        private readonly ReceivedReader $reader,
    ) {}

    public function moved(PeppolDocument $document, string $track, string $to): void
    {
        if ($track === DocumentStatus::TRACK_REPORTING && $to === DocumentStatus::REPORT_FAILED) {
            Log::error('A Peppol document\'s report to the authority failed; it will be asked again', [
                'document_id' => $document->id,
                'org_id' => $document->org_id,
                'asp_message_id' => $document->asp_message_id,
                'errors' => $document->reporting_errors,
            ]);
        }

        $event = self::EVENTS[$track][$to] ?? null;

        if ($event !== null) {
            $this->announce($document, $event);
        }
    }

    public function received(PeppolDocument $document): void
    {
        $this->announce($document, self::RECEIVED);
    }

    /**
     * What every e-invoice event carries: the document's state on both
     * tracks, and what the provider and the authority said.
     *
     * @return array<string, mixed>
     */
    public function payload(PeppolDocument $document): array
    {
        return ['e_invoice' => [
            'id' => $document->id,
            'invoice_id' => $document->invoice_id,
            'number' => $this->reader->summarise($document->xml)->number,
            'direction' => $document->direction,
            'status' => $document->status,
            'reporting_status' => $document->reporting_status,
            'message_id' => $document->asp_message_id,
            'mls_code' => $document->mls_code,
            'errors' => $document->errorList('errors'),
            'authority_reference' => $document->authority_reference,
            'reporting_errors' => $document->errorList('reporting_errors'),
            'sender' => $document->sender_endpoint,
            'receiver' => $document->receiver_endpoint,
        ]];
    }

    private function announce(PeppolDocument $document, string $event): void
    {
        try {
            $this->webhooks->dispatch((string) $document->org_id, $event, $this->payload($document));
        } catch (Throwable $e) {
            // The move is recorded; a webhook that could not be queued must
            // not undo it or fail the callback that made it.
            Log::warning('Peppol: webhook dispatch failed', [
                'document_id' => $document->id,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
