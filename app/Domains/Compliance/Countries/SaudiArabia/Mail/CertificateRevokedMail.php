<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Mail;

use App\Domains\Branding\DTOs\ActiveTheme;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Telling an organization's administrators that one of its ZATCA
 * certificates has been revoked, and whether its device has stopped signing.
 *
 * In the organization's own branding, in the reader's own language.
 */
final class CertificateRevokedMail extends Mailable
{
    public function __construct(
        public readonly string $organizationName,
        public readonly int $version,
        public readonly string $serialNumber,
        public readonly bool $stoppedSigning,
        public readonly ActiveTheme $brand,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('URGENT: ZATCA certificate revoked - :organization', ['organization' => $this->organizationName]));
    }

    public function content(): Content
    {
        return new Content(
            view: 'countries.saudi-arabia.emails.certificate-revoked',
            text: 'countries.saudi-arabia.emails.certificate-revoked-text',
        );
    }
}
