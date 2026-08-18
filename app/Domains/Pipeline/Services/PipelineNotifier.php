<?php

declare(strict_types=1);

namespace App\Domains\Pipeline\Services;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Webhook\Services\WebhookService;
use Illuminate\Support\Facades\Log;

/**
 * Tells the ERP what happened to its invoice, without being able to break it.
 *
 * Every dispatch is best-effort. The invoice is already signed and filed with
 * the authority by the time these run, so a customer's unreachable webhook
 * endpoint must not turn a successful submission into a failed request. A
 * failure is logged and swallowed.
 */
class PipelineNotifier
{
    public function __construct(
        private readonly WebhookService $webhooks,
    ) {}

    public function issued(Invoice $invoice): void
    {
        $this->send($invoice, 'invoice.issued');
    }

    /**
     * @param  list<string>  $errors
     */
    public function rejected(Invoice $invoice, array $errors): void
    {
        $this->send($invoice, 'invoice.rejected', ['errors' => $errors]);
    }

    /**
     * Standard invoices are cleared before issue, simplified ones reported
     * after, so the event name follows the document type.
     *
     * @param  list<string>  $warnings
     */
    public function accepted(Invoice $invoice, array $warnings): void
    {
        $this->send(
            $invoice,
            $invoice->requiresClearance() ? 'invoice.cleared' : 'invoice.reported',
            ['warnings' => $warnings]
        );
    }

    private function send(Invoice $invoice, string $event, array $extra = []): void
    {
        $payload = array_merge([
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status->value,
            'type' => $invoice->type->value,
            'total' => $invoice->total,
            'currency' => $invoice->currency,
        ], $extra);

        try {
            $this->webhooks->dispatch($invoice->organization_id, $event, $payload);
        } catch (\Throwable $e) {
            Log::warning('Pipeline: webhook dispatch failed', [
                'organization_id' => $invoice->organization_id,
                'invoice_id' => $invoice->id,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
