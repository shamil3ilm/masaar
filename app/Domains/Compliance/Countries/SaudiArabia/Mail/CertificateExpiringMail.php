<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Mail;

use App\Domains\Branding\DTOs\ActiveTheme;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Telling an organization's administrators that its certificate is expiring,
 * or has expired.
 *
 * In the organization's own branding - its name, logo and colours - since it
 * is about the organization's own filing. It was a line of plain text.
 *
 * One per till: each EGS unit signs with a certificate of its own, so the
 * mail names the till beside the organization.
 */
final class CertificateExpiringMail extends Mailable
{
    public function __construct(
        public readonly string $organizationName,
        public readonly int $daysRemaining,
        public readonly string $expiryDate,
        public readonly ActiveTheme $brand,
        /** The till whose certificate it is, each having its own; null for none named. */
        public readonly ?string $tillName = null,
    ) {}

    /** The organization, and the till when one is named: what the mail is about. */
    public function subjectName(): string
    {
        return $this->tillName === null ? $this->organizationName : $this->organizationName.' - '.$this->tillName;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->daysRemaining <= 0
            ? __('URGENT: ZATCA certificate expired - :organization', ['organization' => $this->subjectName()])
            : __('ZATCA certificate expires in :days days - :organization', ['days' => $this->daysRemaining, 'organization' => $this->subjectName()]));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.certificate-expiring',
            text: 'emails.certificate-expiring-text',
        );
    }
}
