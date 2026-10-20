<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The organization's e-invoicing start date is near and it has no service
 * provider recorded as appointed: from that date every invoice goes through
 * one (UAE Electronic Invoicing Guidelines v1.1, 6.1). Sent 90, 30 and 7
 * days before (EinvoicingStartReminders).
 */
final class EinvoicingStartReminderMail extends Mailable
{
    public function __construct(
        public readonly string $organizationName,
        public readonly string $startsOn,
        public readonly int $days,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Your e-invoicing starts on :date: appoint your service provider', ['date' => $this->startsOn]));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.einvoicing-start-reminder', text: 'emails.einvoicing-start-reminder-text');
    }
}
