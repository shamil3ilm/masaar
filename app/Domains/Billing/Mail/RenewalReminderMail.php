<?php

declare(strict_types=1);

namespace App\Domains\Billing\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A plan ends soon. Without a renewal the organization stops issuing.
 */
final class RenewalReminderMail extends Mailable
{
    public function __construct(
        public readonly string $organizationName,
        public readonly string $plan,
        public readonly string $endsOn,
        public readonly int $days,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your '.$this->plan.' plan ends in '.$this->days.' '.($this->days === 1 ? 'day' : 'days'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.renewal-reminder', text: 'emails.renewal-reminder-text');
    }
}
