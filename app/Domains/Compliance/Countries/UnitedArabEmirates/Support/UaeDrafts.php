<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Support;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Http\Requests\CreateUaeInvoiceRequest;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Services\InvoiceDrafter;

/**
 * Drafts a UAE invoice from a validated CreateUaeInvoiceRequest.
 *
 * The API and the portal both draft through this, so the mapping of the
 * module's InvoiceRules - draft() for the shared columns, countryFields()
 * for the UAE's - is applied one way. The draft itself is the shared
 * InvoiceDrafter's.
 */
class UaeDrafts
{
    public function __construct(
        private readonly InvoiceDrafter $drafter,
    ) {}

    public function draft(CreateUaeInvoiceRequest $request, string $organizationId): Invoice
    {
        return $this->drafter->draft(
            $request->draft(),
            $organizationId,
            countryFields: $request->countryFields(),
        );
    }
}
