<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\Services;

use App\Domains\Compliance\Engine\Contracts\Jurisdiction;
use App\Domains\Compliance\Engine\Exceptions\JurisdictionUnavailableException;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;

/**
 * Which jurisdiction an organization, or an invoice, belongs to.
 *
 * The organization's country decides, and it decides alone: an organization
 * has one country. A blank country is a row from before the column was set,
 * and answers with the default module.
 */
class JurisdictionResolver
{
    public function __construct(private readonly JurisdictionRegistry $registry) {}

    /**
     * @throws JurisdictionUnavailableException for a country with no module, or one switched off
     */
    public function forOrganization(Organization $organization): Jurisdiction
    {
        $code = blank($organization->country)
            ? $this->registry->defaultCode()
            : (string) $organization->country;

        $jurisdiction = $this->registry->get($code);

        if (! $jurisdiction->enabled()) {
            throw JurisdictionUnavailableException::disabled($code, $jurisdiction->name());
        }

        return $jurisdiction;
    }

    /**
     * @throws JurisdictionUnavailableException
     */
    public function forInvoice(Invoice $invoice): Jurisdiction
    {
        return $this->forOrganization(Organization::query()->findOrFail($invoice->org_id));
    }
}
