<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\Events;

/**
 * Event fired when invoice submission fails due to technical errors.
 *
 * This is different from rejection - failure means the submission
 * could not be processed (network error, timeout, etc.).
 */
class InvoiceFailed extends BaseInvoiceEvent
{
    public function getWebhookEventName(): string
    {
        return 'invoice.failed';
    }

    public function getWebhookPayload(): array
    {
        return array_merge(parent::getWebhookPayload(), [
            'error_code' => $this->submission->last_error_code,
            'error_message' => $this->submission->last_error_message,
            'retry_count' => $this->submission->retry_count,
            'max_retries' => $this->submission->max_retries,
            'will_retry' => $this->submission->next_retry_at !== null,
            'next_retry_at' => $this->submission->next_retry_at?->toIso8601String(),
        ]);
    }
}
