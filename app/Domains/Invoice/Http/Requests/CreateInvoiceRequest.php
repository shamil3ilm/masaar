<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Http\Requests;

use App\Domains\Compliance\Engine\Contracts\InvoiceRules;
use App\Domains\Compliance\Engine\Contracts\Jurisdiction;
use App\Domains\Compliance\Engine\Services\JurisdictionResolver;
use App\Domains\Organization\Services\TenantResolver;

/**
 * An invoice draft from the API, checked by the rules of the organization's
 * country (docs/architecture/JURISDICTION-ROUTING.md).
 *
 * The organization is the one the caller acts for. Its country's rules
 * apply whether or not the country issues now, since a draft can still be
 * saved while it is switched off; with no organization, the default
 * country's do.
 */
class CreateInvoiceRequest extends CountryInvoiceRequest
{
    /** The organization's country's module. */
    protected function jurisdiction(): Jurisdiction
    {
        return app(JurisdictionResolver::class)->forRules(app(TenantResolver::class)->getOrganization());
    }

    protected function resolveInvoiceRules(): InvoiceRules
    {
        return $this->jurisdiction()->invoiceRules();
    }
}
