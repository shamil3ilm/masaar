<?php

declare(strict_types=1);

namespace App\Domains\Billing\Mail;

use App\Support\Brand;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Bills a recurring cost expected and nobody has entered: each sent once,
 * to the admins who pay the platform's suppliers, in their own language
 * (HasLocalePreference). Queued; it carries the lines, not the models.
 */
final class MissingBillsMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  list<array{supplier: string, description: string, expected_on: string, amount: string, url: string}>  $bills
     */
    public function __construct(
        public readonly array $bills,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Expected bills not entered at :app: :count', [
            'app' => Brand::name(app()->getLocale()), 'count' => count($this->bills),
        ]));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.missing-bills', text: 'emails.missing-bills-text');
    }
}
