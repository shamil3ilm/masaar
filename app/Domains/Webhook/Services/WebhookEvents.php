<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Services;

/**
 * The events an endpoint can subscribe to (WebhookService::EVENTS), each
 * with the words the portal offers it in. Which ones a country sends is the
 * country's: filing events where documents are filed with the authority,
 * e-invoice events where they travel a network.
 */
final class WebhookEvents
{
    /**
     * @return array<string, string> event => what it means, in the catalogue's order
     */
    public static function labels(): array
    {
        $labels = [];

        foreach (WebhookService::EVENTS as $event) {
            $labels[$event] = self::label($event);
        }

        return $labels;
    }

    public static function label(string $event): string
    {
        return match ($event) {
            'invoice.issued' => __('An invoice was issued'),
            'invoice.submitted' => __('An invoice was sent to the tax authority'),
            'invoice.cleared' => __('An invoice was cleared by the tax authority'),
            'invoice.reported' => __('An invoice was reported to the tax authority'),
            'invoice.warning' => __('An invoice was accepted with warnings'),
            'invoice.rejected' => __('An invoice was rejected by the tax authority'),
            'invoice.failed' => __('Filing an invoice failed'),
            'onboarding.ccsid_obtained' => __('A compliance certificate was obtained'),
            'onboarding.compliance_passed' => __('The compliance checks were passed'),
            'onboarding.pcsid_obtained' => __('A production certificate was obtained'),
            'certificate.expiring' => __('A certificate is about to expire'),
            'e_invoice.sent' => __('An e-invoice was sent'),
            'e_invoice.delivered' => __('An e-invoice was delivered to the buyer'),
            'e_invoice.rejected' => __('An e-invoice was rejected'),
            'e_invoice.failed' => __('Sending an e-invoice failed'),
            'e_invoice.reported' => __('An e-invoice was reported to the tax authority'),
            'e_invoice.report_failed' => __('Reporting an e-invoice failed'),
            'e_invoice.received' => __('An e-invoice was received'),
            'webhook.test' => __('A test event'),
            default => $event,
        };
    }
}
