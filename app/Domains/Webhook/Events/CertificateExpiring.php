<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event fired when a ZATCA certificate is approaching expiry.
 *
 * This event triggers webhook notifications to customer systems
 * so they can take proactive action to renew certificates.
 */
class CertificateExpiring
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $organizationId,
        public readonly int $daysRemaining,
        public readonly string $expiryDate,
    ) {}

    /**
     * Get the webhook event name.
     */
    public function getWebhookEventName(): string
    {
        return 'certificate.expiring';
    }

    /**
     * Get the webhook payload.
     */
    public function getWebhookPayload(): array
    {
        return [
            'event' => $this->getWebhookEventName(),
            'data' => [
                'organization_id' => $this->organizationId,
                'days_remaining' => $this->daysRemaining,
                'expiry_date' => $this->expiryDate,
                'severity' => $this->getSeverity(),
                'message' => $this->getMessage(),
            ],
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Get severity level based on days remaining.
     */
    private function getSeverity(): string
    {
        if ($this->daysRemaining <= 0) {
            return 'critical';
        }

        if ($this->daysRemaining <= 7) {
            return 'high';
        }

        if ($this->daysRemaining <= 14) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Get human-readable message.
     */
    private function getMessage(): string
    {
        if ($this->daysRemaining <= 0) {
            return 'ZATCA certificate has EXPIRED. Invoice submissions will fail.';
        }

        if ($this->daysRemaining === 1) {
            return 'ZATCA certificate expires TOMORROW. Renew immediately.';
        }

        return "ZATCA certificate expires in {$this->daysRemaining} days.";
    }
}
