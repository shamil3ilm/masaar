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
 *
 * Issue is the only event raised here. The submission outcomes — cleared,
 * reported, rejected — are state events raised by SubmissionTracker, so they
 * carry the same payload whether the submission ran inline or on the queue.
 * This class announced them too, under the same names with a different shape.
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
            $this->webhooks->dispatch($invoice->org_id, $event, $payload);
        } catch (\Throwable $e) {
            Log::warning('Pipeline: webhook dispatch failed', [
                'org_id' => $invoice->org_id,
                'invoice_id' => $invoice->id,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
