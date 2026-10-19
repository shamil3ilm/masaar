<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The last date to appoint an e-invoicing service provider is near, and the
 * organization has not recorded one (UAE Electronic Invoicing Guidelines
 * v1.1, p14, p19).
 */
final class ProviderAppointmentReminderMail extends Mailable
{
    public function __construct(
        public readonly string $organizationName,
        public readonly string $deadline,
        public readonly int $days,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->days === 1
            ? __('Appoint your e-invoicing service provider by tomorrow')
            : __('Appoint your e-invoicing service provider within :days days', ['days' => $this->days]));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.provider-appointment-reminder', text: 'emails.provider-appointment-reminder-text');
    }
}
