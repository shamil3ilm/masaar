<?php

declare(strict_types=1);

namespace App\Domains\Support\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A board item someone follows moved on, or got a note. Only its
 * reference, its title and a link: the update itself is on the board.
 */
final class BoardUpdateMail extends Mailable
{
    public function __construct(
        public readonly string $reference,
        public readonly string $title,
        public readonly ?string $titleAr,
        public readonly string $url,
    ) {}

    /** The title in the language the mail is written in. */
    public function localTitle(): string
    {
        return app()->getLocale() === 'ar' && filled($this->titleAr) ? (string) $this->titleAr : $this->title;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: '['.$this->reference.'] '.__('A feature you follow has an update'));
    }

    public function content(): Content
    {
        // Built in the recipient's language, as the subject is.
        return new Content(view: 'emails.board-update', text: 'emails.board-update-text', with: ['localTitle' => $this->localTitle()]);
    }
}
