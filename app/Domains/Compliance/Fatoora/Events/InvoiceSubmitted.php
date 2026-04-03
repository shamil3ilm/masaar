<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Events;

/**
 * Event fired when an invoice is submitted to ZATCA.
 *
 * This indicates the submission is in progress and
 * awaiting a response from ZATCA.
 */
class InvoiceSubmitted extends BaseInvoiceEvent
{
    public function getWebhookEventName(): string
    {
        return 'invoice.submitted';
    }

    public function getWebhookPayload(): array
    {
        return array_merge(parent::getWebhookPayload(), [
            'submitted_at' => $this->submission->submitted_at?->toIso8601String(),
        ]);
    }
}
