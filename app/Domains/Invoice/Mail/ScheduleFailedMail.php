<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A schedule's invoice for one period could not be made, and the runner has
 * stopped trying: someone has to look.
 */
final class ScheduleFailedMail extends Mailable
{
    public function __construct(
        public readonly string $scheduleName,
        public readonly string $periodStart,
        public readonly string $periodEnd,
        public readonly string $error,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('A scheduled invoice was not made: :name', ['name' => $this->scheduleName]));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.schedule-failed', text: 'emails.schedule-failed-text');
    }
}
