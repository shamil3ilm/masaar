<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Exceptions;

use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\Product;
use RuntimeException;

/**
 * A customer or product was asked to be removed after an invoice used it.
 *
 * It stays for the history of what was invoiced: it can be archived, out of
 * the pickers, but not removed (CatalogueRemoval).
 */
final class RecordInUseException extends RuntimeException
{
    public static function for(Customer|Product $record): self
    {
        return new self(__(':name is on an invoice, so it stays for the record: archive it instead.', ['name' => $record->name]));
    }
}
