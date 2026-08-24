<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Events;

use App\Domains\Compliance\Fatoora\Models\InvoiceSubmission;
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
     * Raise the event for a submission state, if that state has one.
     *
     * Defined here so the synchronous and queued paths announce an outcome the
     * same way. The queue job had this mapping privately and SubmissionTracker
     * had nothing, so a submission processed synchronously produced no event at
     * all — and therefore no webhook from the listener, while the same outcome
     * queued produced one. What an integrator received depended on which path
     * the platform happened to take.
     *
     * @param  array<string, mixed>  $context
     */
    public static function raise(InvoiceSubmission $submission, string $state, array $context = []): void
    {
        $event = match ($state) {
            'cleared' => new InvoiceCleared($submission, $context),
            'reported' => new InvoiceReported($submission, $context),
            'rejected' => new InvoiceRejected($submission, $context),
            'warning' => new InvoiceWarning($submission, $context),
            'failed' => new InvoiceFailed($submission, $context),
            default => null,
        };

        if ($event !== null) {
            event($event);
        }
    }

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
        return $this->submission->org_id;
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
            'org_id' => $this->submission->org_id,
            'invoice_number' => $invoice?->invoice_number,
            'invoice_type' => $invoice?->type,
            'issue_date' => $invoice?->issue_date?->toIso8601String(),
            // The column is total. There is no total_with_vat on Invoice, and
            // Eloquent answers null for an attribute it does not have rather
            // than failing — a webhook would report no amount at all.
            'total_amount' => $invoice?->total,
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
