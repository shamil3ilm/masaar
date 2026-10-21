<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Mail;

use App\Domains\Branding\DTOs\ActiveTheme;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Telling an organization's administrators that ZATCA refused one of its
 * devices' credentials, so filing from it is paused until they renew its
 * certificate (FilingPause).
 *
 * In the organization's own branding, in the reader's own language.
 */
final class FilingPausedMail extends Mailable
{
    public function __construct(
        public readonly string $organizationName,
        public readonly string $device,
        public readonly ActiveTheme $brand,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Action needed: ZATCA filing paused - :organization', ['organization' => $this->organizationName]));
    }

    public function content(): Content
    {
        return new Content(
            view: 'countries.saudi-arabia.emails.filing-paused',
            text: 'countries.saudi-arabia.emails.filing-paused-text',
        );
    }
}
