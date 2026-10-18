<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * An allowance the customer was given is nearly used, or used up.
 */
final class AllowanceWarningMail extends Mailable
{
    public function __construct(
        public readonly string $organizationName,
        public readonly string $allowance,
        public readonly int $percent,
        public readonly int $used,
        public readonly int $allowed,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->percent >= 100
            ? __('You have used your allowance of :what', ['what' => $this->what()])
            : __('You have used :percent% of your allowance of :what', ['percent' => $this->percent, 'what' => $this->what()]));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.allowance-warning', text: 'emails.allowance-warning-text', with: ['what' => $this->what()]);
    }

    /** What the allowance counts, in the reader's language. */
    private function what(): string
    {
        return $this->allowance === 'calls_per_day' ? __('API calls a day') : __('invoices a month');
    }
}
