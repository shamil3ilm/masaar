<?php

declare(strict_types=1);

namespace App\Domains\Auth\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent to the old address once the change is made, so its owner learns of a
 * change they did not make.
 */
final class EmailChangedMail extends Mailable
{
    public function __construct(
        public readonly string $name,
        public readonly string $newEmail,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Your email address was changed'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.email-changed', text: 'emails.email-changed-text');
    }
}
