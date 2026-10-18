<?php

declare(strict_types=1);

namespace App\Domains\Auth\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * An invitation to run the platform as one of its admins.
 */
final class AdminInvitationMail extends Mailable
{
    public function __construct(
        public readonly string $inviterName,
        public readonly string $role,
        public readonly string $acceptUrl,
        public readonly int $validDays,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __(':name invited you to be a platform admin', ['name' => $this->inviterName]));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin-invitation',
            text: 'emails.admin-invitation-text',
        );
    }
}
