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

    /**
     * @param  string|null  $branchId  the branch it is issued from (BranchAccess::draftBranch)
     * @param  string|null  $formToken  the portal form's token (DraftForm), kept so the form sent again finds this draft
     */
    public function draft(CreateUaeInvoiceRequest $request, string $organizationId, ?string $branchId = null, ?string $formToken = null): Invoice
    {
        return $this->drafter->draft(
            [...$request->draft(), ...($formToken === null ? [] : ['idempotency_key' => $formToken])],
            $organizationId,
            $branchId,
            countryFields: $request->countryFields(),
        );
    }
}
