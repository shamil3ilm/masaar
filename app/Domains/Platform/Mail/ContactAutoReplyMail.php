<?php

declare(strict_types=1);

namespace App\Domains\Platform\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The note a sender of the contact form gets back, when the platform sends
 * one (Settings: site.contact_auto_reply): an admin's own words, in English
 * and Arabic, the sender's language first.
 *
 * It repeats nothing the sender typed, not even their name: anyone can put
 * any address into the form, and this must not carry their words to it.
 */
final class ContactAutoReplyMail extends Mailable
{
    public function __construct(
        public readonly string $english,
        public readonly string $arabic,
        public readonly string $senderLocale,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('We have your message', [], 'en').' / '.__('We have your message', [], 'ar'));
    }

    public function content(): Content
    {
        $arabicFirst = $this->senderLocale === 'ar';

        return new Content(
            view: 'emails.contact-auto-reply',
            text: 'emails.contact-auto-reply-text',
            with: [
                'parts' => $arabicFirst
                    ? [['ar', $this->arabic], ['en', $this->english]]
                    : [['en', $this->english], ['ar', $this->arabic]],
            ],
        );
    }
}
