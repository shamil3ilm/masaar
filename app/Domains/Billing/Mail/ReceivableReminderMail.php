<?php

declare(strict_types=1);

namespace App\Domains\Billing\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A reminder that invoices from the platform fall due soon, or are past
 * due: each listed with what it still owes, the accounts to pay into, and
 * a link to the account's statement.
 *
 * In both languages, the reader's first: whoever pays the invoice may read
 * either, and an invoice the email is about is itself in both.
 */
final class ReceivableReminderMail extends Mailable
{
    public const DUE = 'due';

    public const OVERDUE = 'overdue';

    /**
     * @param  list<array{number: string, due_date: string, balance: string, currency: string}>  $invoices
     * @param  list<array{bank_name: string, account_name: string, iban: string}>  $accounts
     */
    public function __construct(
        public readonly string $organizationName,
        public readonly string $kind,
        public readonly int $days,
        public readonly array $invoices,
        public readonly array $accounts,
        public readonly string $statementUrl,
    ) {}

    /** The reader's language first, then the other. */
    public function locales(): array
    {
        $first = $this->locale ?? app()->getLocale();

        return $first === 'ar' ? ['ar', 'en'] : ['en', 'ar'];
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: implode(' / ', array_map(fn (string $locale): string => $this->subjectIn($locale), $this->locales())));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.receivable-reminder', text: 'emails.receivable-reminder-text', with: ['locales' => $this->locales()]);
    }

    private function subjectIn(string $locale): string
    {
        return $this->kind === self::OVERDUE
            ? __('An invoice is :days days past due', ['days' => $this->days], $locale)
            : __('An invoice falls due in :days days', ['days' => $this->days], $locale);
    }
}
