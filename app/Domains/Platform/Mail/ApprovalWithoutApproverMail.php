<?php

declare(strict_types=1);

namespace App\Domains\Platform\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * To an organization's owners and admins: something its policy holds waits
 * for approval, and nobody besides the person who asked could approve it
 * (OrganizationApprovals). It waits until someone can; they add an owner or
 * admin, or turn the approval off and send it again. In the reader's own
 * language (HasLocalePreference).
 */
final class ApprovalWithoutApproverMail extends Mailable
{
    public function __construct(
        public readonly string $action,
        public readonly string $summary,
        public readonly string $organizationName,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Waiting for an approver: :action', ['action' => $this->action]));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.approval-without-approver',
            text: 'emails.approval-without-approver-text',
        );
    }
}
