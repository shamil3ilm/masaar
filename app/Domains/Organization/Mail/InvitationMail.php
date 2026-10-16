<?php

declare(strict_types=1);

namespace App\Domains\Organization\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * An invitation to join an organization on the platform.
 */
final class InvitationMail extends Mailable
{
    public function __construct(
        public readonly string $organizationName,
        public readonly string $inviterName,
        public readonly string $role,
        public readonly string $acceptUrl,
        public readonly int $validDays,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->inviterName} invited you to {$this->organizationName}");
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.invitation',
            text: 'emails.invitation-text',
        );
    }
}
