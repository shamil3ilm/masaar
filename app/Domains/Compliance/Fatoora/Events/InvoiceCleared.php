<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Events;

/**
 * Event fired when a B2B invoice is successfully cleared by ZATCA.
 *
 * Clearance means ZATCA has validated and approved the invoice.
 * The invoice can now be legally issued to the buyer.
 */
class InvoiceCleared extends BaseInvoiceEvent
{
    public function getWebhookEventName(): string
    {
        return 'invoice.cleared';
    }

    public function getWebhookPayload(): array
    {
        return array_merge(parent::getWebhookPayload(), [
            'clearance_status' => $this->submission->clearance_status,
            'cleared_at' => $this->submission->cleared_at?->toIso8601String(),
            'invoice_hash' => $this->submission->invoice_hash,
        ]);
    }
}
