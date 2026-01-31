<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\Events;

/**
 * Event fired when an invoice is accepted with warnings.
 *
 * The invoice was processed successfully but ZATCA flagged
 * non-critical issues that should be addressed.
 */
class InvoiceWarning extends BaseInvoiceEvent
{
    public function getWebhookEventName(): string
    {
        return 'invoice.warning';
    }

    public function getWebhookPayload(): array
    {
        return array_merge(parent::getWebhookPayload(), [
            'warnings' => $this->submission->zatca_warnings,
            'warning_count' => is_array($this->submission->zatca_warnings)
                ? count($this->submission->zatca_warnings)
                : 0,
            'clearance_status' => $this->submission->clearance_status,
            'reporting_status' => $this->submission->reporting_status,
        ]);
    }
}
