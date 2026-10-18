<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Simplified invoices were not reported to ZATCA within twenty-four hours of
 * issue - or, with $resolved, have now been reported - and what the seller
 * must do (ZATCA Detailed Guidelines for E-Invoicing, p48): notify ZATCA
 * through the form on its website, and notify it again once reported.
 *
 * In Arabic and English both, whatever the reader's language: it is an
 * instruction from the authority's rules that whoever handles it must be
 * able to follow, and pass on.
 */
final class LateReportingMail extends Mailable
{
    /**
     * @param  list<array{number: string, issued_at: string}>  $documents
     */
    public function __construct(
        public readonly string $organizationName,
        public readonly array $documents,
        public readonly bool $resolved,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->resolved
            ? 'تم الإبلاغ عن الفواتير المتأخرة - أبلغ الهيئة مرة أخرى / Late invoices now reported - notify ZATCA again: '.$this->organizationName
            : 'فواتير لم يُبلَّغ عنها خلال 24 ساعة - أبلغ الهيئة / Invoices not reported within 24 hours - notify ZATCA: '.$this->organizationName);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.late-reporting', text: 'emails.late-reporting-text');
    }
}
