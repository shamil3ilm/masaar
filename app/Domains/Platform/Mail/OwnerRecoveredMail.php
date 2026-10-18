<?php

declare(strict_types=1);

namespace App\Domains\Platform\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * To the platform's owner, every time the break-glass command ran on the
 * server (masaar:owner-recover): what it did and why, so a run the owner did
 * not make is noticed at once.
 */
final class OwnerRecoveredMail extends Mailable
{
    public const ENABLED_BYPASS = 'enable_bypass';

    public const APPROVED_REQUEST = 'approve_request';

    /**
     * @param  string  $done  ENABLED_BYPASS or APPROVED_REQUEST
     * @param  string|null  $summary  the request approved
     */
    public function __construct(
        public readonly string $done,
        public readonly ?string $summary,
        public readonly string $reason,
        public readonly string $at,
        public readonly string $policiesUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Approvals were changed from the server'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.owner-recovered',
            text: 'emails.owner-recovered-text',
        );
    }
}
