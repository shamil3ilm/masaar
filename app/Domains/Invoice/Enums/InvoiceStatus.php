<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Enums;

/**
 * Invoice lifecycle states.
 *
 * Draft → Issued → Accepted/Rejected
 *
 * Once issued, invoice data becomes immutable for compliance.
 *
 * Writers: InvoiceDrafter creates a draft, IssuingLifecycle issues it, and
 * the country's verdict on a filing (an authority's judgement, never an
 * outage) accepts or rejects it. Where the filing itself is - queued, sent,
 * held - is the country's submission record, not this status; there was a
 * "submitted" status, and nothing ever set it. A draft that is abandoned is
 * deleted: there is no cancelled or void status, because an issued document
 * is never withdrawn, only corrected by a credit or debit note.
 */
enum InvoiceStatus: string
{
    case Draft = 'draft';           // Editable, not yet finalized
    case Issued = 'issued';         // Finalized, hash generated, immutable
    case Accepted = 'accepted';     // The authority accepted it
    case Rejected = 'rejected';     // The authority rejected it

    /**
     * Check if invoice can still be edited.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Check if invoice has been finalized.
     */
    public function isFinalized(): bool
    {
        return $this !== self::Draft;
    }

    /**
     * The statuses this one may move to. A rejected invoice may still be
     * accepted, or rejected again, by a filing retried after it.
     *
     * @return list<self>
     */
    public function next(): array
    {
        return match ($this) {
            self::Draft => [self::Issued],
            self::Issued => [self::Accepted, self::Rejected],
            self::Rejected => [self::Accepted, self::Rejected],
            self::Accepted => [],
        };
    }

    public function allows(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }
}
