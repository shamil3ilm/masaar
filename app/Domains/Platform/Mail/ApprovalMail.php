<?php

declare(strict_types=1);

namespace App\Domains\Platform\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * An admin action waiting for someone's approval, or the decision on one
 * someone asked for. In the reader's own language (HasLocalePreference).
 */
final class ApprovalMail extends Mailable
{
    public const REQUESTED = 'requested';

    public const DECIDED = 'decided';

    public function __construct(
        public readonly string $kind,
        public readonly string $action,
        public readonly string $summary,
        public readonly string $status,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->kind === self::REQUESTED
            ? __('Waiting for your approval: :action', ['action' => $this->action])
            : __('Your request was decided: :action', ['action' => $this->action]));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.approval',
            text: 'emails.approval-text',
        );
    }
}
