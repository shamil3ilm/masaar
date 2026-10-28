<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Invoice\Exceptions\RecordInUseException;
use App\Domains\Invoice\Models\Customer;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceLine;
use App\Domains\Invoice\Models\Product;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Archive if used, remove if not (owner decision).
 *
 * A customer an invoice was written for (invoices.customer_id), or a product
 * a line was made from (invoice_lines.product_id), stays for the history of
 * what was invoiced: it can only be archived. One never used can be removed
 * - soft-deleted, with who removed it (RecordsActors) - and brought back
 * from the list's Removed view. Either way an invoice keeps the details it
 * was written with.
 */
class CatalogueRemoval
{
    /** Whether any invoice, a draft included, has used it. */
    public function inUse(Customer|Product $record): bool
    {
        return $record instanceof Customer
            ? Invoice::acrossTenants()->where('customer_id', $record->id)->exists()
            : InvoiceLine::query()->where('product_id', $record->id)->exists();
    }

    /**
     * @throws RecordInUseException when an invoice has used it
     */
    public function remove(Customer|Product $record): void
    {
        if ($this->inUse($record)) {
            throw RecordInUseException::for($record);
        }

        // Through the model, so who removed it is kept.
        $record->delete();
    }

    /**
     * @throws DomainException when another kept record took its VAT number or code meanwhile
     */
    public function restore(Customer|Product $record): void
    {
        try {
            $record->restore();
        } catch (UniqueConstraintViolationException) {
            throw new DomainException($record instanceof Customer
                ? __('Another customer already has this VAT number.')
                : __('Another product already has this code.'));
        }
    }

    /** A removed customer of the organization's, to bring back; null when there is none. */
    public function removedCustomer(string $organizationId, string $customerId): ?Customer
    {
        return Customer::acrossTenants()->onlyTrashed()->where('org_id', $organizationId)->find($customerId);
    }

    /** A removed product of the organization's, to bring back; null when there is none. */
    public function removedProduct(string $organizationId, string $productId): ?Product
    {
        return Product::acrossTenants()->onlyTrashed()->where('org_id', $organizationId)->find($productId);
    }
}
