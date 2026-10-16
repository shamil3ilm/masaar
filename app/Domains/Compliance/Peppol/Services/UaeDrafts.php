<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Peppol\Services;

use App\Domains\Compliance\Peppol\Http\Requests\CreateUaeInvoiceRequest;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\InvoiceDrafter;

/**
 * Drafts a UAE invoice from a validated CreateUaeInvoiceRequest.
 *
 * The API and the portal both draft through this, so the request's mapping -
 * invoice() for the shared columns, jurisdictionFields() for the UAE's - is
 * applied one way. The draft itself is InvoiceDrafter's, as a Saudi one is.
 */
class UaeDrafts
{
    public function __construct(
        private readonly InvoiceDrafter $drafter,
    ) {}

    public function draft(CreateUaeInvoiceRequest $request, string $organizationId): Invoice
    {
        return $this->drafter->draft(
            $request->invoice(),
            $organizationId,
            countryFields: $request->jurisdictionFields(),
        );
    }
}
