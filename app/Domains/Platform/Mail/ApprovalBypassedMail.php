<?php

declare(strict_types=1);

namespace App\Domains\Platform\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * To the platform's owner, every time: an action its policy holds went
 * through because no other admin could ever approve it. What changed, by
 * whom and why, in the owner's own language.
 */
final class ApprovalBypassedMail extends Mailable
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function __construct(
        public readonly string $action,
        public readonly string $summary,
        public readonly array $before,
        public readonly array $after,
        public readonly string $requester,
        public readonly ?string $reason,
        public readonly string $policiesUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Done without approval: :action', ['action' => $this->action]));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.approval-bypassed',
            text: 'emails.approval-bypassed-text',
        );
    }
}
