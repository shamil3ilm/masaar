<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Mail;

use App\Domains\Branding\DTOs\ActiveTheme;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Telling an organization's administrators that a till they asked to retire
 * has filed everything it signed and is now retired (FinishRetirements).
 *
 * In the organization's own branding, in the reader's own language.
 */
final class TillRetiredMail extends Mailable
{
    public function __construct(
        public readonly string $organizationName,
        public readonly string $tillName,
        public readonly ActiveTheme $brand,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __(':till is retired - :organization', ['till' => $this->tillName, 'organization' => $this->organizationName]));
    }

    public function content(): Content
    {
        return new Content(
            view: 'countries.saudi-arabia.emails.till-retired',
            text: 'countries.saudi-arabia.emails.till-retired-text',
        );
    }
}
