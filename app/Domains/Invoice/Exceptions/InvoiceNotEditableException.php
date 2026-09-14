<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Exceptions;

use App\Domains\Invoice\Models\Invoice;
use RuntimeException;

/**
 * An invoice was asked to change after leaving draft.
 *
 * Once issued, an invoice is a tax document: it is corrected with a credit or
 * debit note, never edited or deleted.
 */
final class InvoiceNotEditableException extends RuntimeException
{
    public static function for(Invoice $invoice): self
    {
        return new self("Invoice {$invoice->id} is {$invoice->status->value}; only a draft can change.");
    }
}
