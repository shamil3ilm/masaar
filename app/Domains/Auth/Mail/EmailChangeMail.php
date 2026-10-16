<?php

declare(strict_types=1);

namespace App\Domains\Auth\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Confirm a new email address, sent to that address.
 */
final class EmailChangeMail extends Mailable
{
    public function __construct(
        public readonly string $name,
        public readonly string $confirmUrl,
        public readonly int $validHours,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirm your new email address');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.email-change', text: 'emails.email-change-text');
    }
}
