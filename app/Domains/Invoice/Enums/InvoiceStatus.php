<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Enums;

/**
 * Invoice lifecycle states.
 *
 * Draft → Issued → Submitted → Accepted/Rejected
 *
 * Once issued, invoice data becomes immutable for compliance.
 */
enum InvoiceStatus: string
{
    case Draft = 'draft';           // Editable, not yet finalized
    case Issued = 'issued';         // Finalized, hash generated, immutable
    case Submitted = 'submitted';   // Sent to ZATCA
    case Accepted = 'accepted';     // ZATCA accepted
    case Rejected = 'rejected';     // ZATCA rejected

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
}
