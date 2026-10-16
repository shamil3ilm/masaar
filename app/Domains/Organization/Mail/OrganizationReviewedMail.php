<?php

declare(strict_types=1);

namespace App\Domains\Organization\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The platform's decision on a company that signed itself up.
 */
final class OrganizationReviewedMail extends Mailable
{
    public function __construct(
        public readonly string $organizationName,
        public readonly bool $approved,
        public readonly ?string $note,
        public readonly string $portalUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->approved
            ? __(':organization is approved - connect to ZATCA', ['organization' => $this->organizationName])
            : __(':organization was not approved', ['organization' => $this->organizationName]));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.organization-reviewed',
            text: 'emails.organization-reviewed-text',
        );
    }
}
