<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\Events;

/**
 * Event fired when a B2C invoice is successfully reported to ZATCA.
 *
 * Reporting means ZATCA has acknowledged receipt of the invoice.
 * B2C invoices don't require clearance, only reporting.
 */
class InvoiceReported extends BaseInvoiceEvent
{
    public function getWebhookEventName(): string
    {
        return 'invoice.reported';
    }

    public function getWebhookPayload(): array
    {
        return array_merge(parent::getWebhookPayload(), [
            'reporting_status' => $this->submission->reporting_status,
        ]);
    }
}
