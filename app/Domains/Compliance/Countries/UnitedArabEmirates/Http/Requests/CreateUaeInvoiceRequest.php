<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Http\Requests;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\InvoiceRules;
use App\Domains\Compliance\Engine\Contracts\InvoiceRules as InvoiceRulesPort;
use App\Domains\Invoice\Http\Requests\CountryInvoiceRequest;

/**
 * A UAE invoice draft for /api/compliance/ae/invoices, in the shapes PINT AE
 * checks: the module's InvoiceRules, which the ERP pipeline and the portal's
 * UAE form draft by too.
 *
 * The draft is made by the shared InvoiceDrafter, from draft(), with
 * countryFields() beside it - the due date and the jurisdiction_data keys
 * PintMapper reads.
 */
class CreateUaeInvoiceRequest extends CountryInvoiceRequest
{
    protected function resolveInvoiceRules(): InvoiceRulesPort
    {
        return app(InvoiceRules::class);
    }
}
