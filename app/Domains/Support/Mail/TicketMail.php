<?php

declare(strict_types=1);

namespace App\Domains\Support\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Something happened on a support ticket: it was opened, or someone replied.
 *
 * The message itself is not in the email - only that there is one, and a
 * link. A ticket can carry invoice details and attachments, and an email is
 * forwarded and kept in places a ticket is not.
 */
final class TicketMail extends Mailable
{
    public function __construct(
        public readonly string $headline,
        public readonly string $reference,
        public readonly string $subjectLine,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '['.$this->reference.'] '.$this->headline);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.support-ticket', text: 'emails.support-ticket-text');
    }
}
