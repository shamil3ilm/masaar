<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\Events;

use App\Domains\Compliance\Zatca\Models\InvoiceSubmission;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Base event for invoice state transitions.
 *
 * All invoice events extend this class and carry the submission
 * context needed for webhook dispatch and other listeners.
 */
abstract class BaseInvoiceEvent
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * The webhook event name for this event type.
     */
    abstract public function getWebhookEventName(): string;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public readonly InvoiceSubmission $submission,
        public readonly array $context = []
    ) {}

    /**
     * Get the invoice ID.
     */
    public function getInvoiceId(): string
    {
        return $this->submission->invoice_id;
    }

    /**
     * Get the organization ID.
     */
    public function getOrganizationId(): string
    {
        return $this->submission->organization_id;
    }

    /**
     * Get the submission state.
     */
    public function getState(): string
    {
        return $this->submission->state;
    }

    /**
     * Get the webhook payload data.
     */
    public function getWebhookPayload(): array
    {
        $invoice = $this->submission->invoice;

        return array_merge([
            'submission_id' => $this->submission->id,
            'invoice_id' => $this->submission->invoice_id,
            'organization_id' => $this->submission->organization_id,
            'invoice_number' => $invoice?->invoice_number,
            'invoice_type' => $invoice?->type,
            'issue_date' => $invoice?->issue_date?->toIso8601String(),
            'total_amount' => $invoice?->total_with_vat,
            'currency' => $invoice?->currency ?? 'SAR',
            'icv' => $invoice?->icv,
            'state' => $this->submission->state,
            'previous_state' => $this->submission->previous_state,
            'submission_type' => $this->submission->submission_type,
            'zatca_uuid' => $this->submission->zatca_uuid,
            'timestamp' => now()->toIso8601String(),
        ], $this->context);
    }
}
