<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * An issued invoice, sent to its buyer with the PDF attached.
 *
 * The PDF carries the XML inside it, so one attachment is the whole invoice.
 */
final class InvoiceMail extends Mailable
{
    public function __construct(
        public readonly string $sellerName,
        public readonly string $invoiceNumber,
        public readonly string $total,
        public readonly string $pdf,
        public readonly string $fileName,
        public readonly ?string $replyToAddress = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Invoice '.$this->invoiceNumber.' from '.$this->sellerName,
            replyTo: $this->replyToAddress === null ? [] : [$this->replyToAddress],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.invoice', text: 'emails.invoice-text');
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [Attachment::fromData(fn (): string => $this->pdf, $this->fileName)->withMime('application/pdf')];
    }
}
