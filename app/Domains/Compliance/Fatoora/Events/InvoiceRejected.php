<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Events;

/**
 * Event fired when an invoice is rejected by ZATCA.
 *
 * Rejection means ZATCA has found validation errors.
 * The invoice must be corrected and resubmitted.
 */
class InvoiceRejected extends BaseInvoiceEvent
{
    public function getWebhookEventName(): string
    {
        return 'invoice.rejected';
    }

    public function getWebhookPayload(): array
    {
        return array_merge(parent::getWebhookPayload(), [
            'errors' => $this->submission->zatca_errors,
            'error_count' => is_array($this->submission->zatca_errors)
                ? count($this->submission->zatca_errors)
                : 0,
        ]);
    }
}
