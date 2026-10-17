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
     * The module whose rules an organization's forms and requests follow:
     * its country's, whether or not the country is switched on, since a
     * switched-off country's drafts can still be saved. The default module
     * for no organization, a blank country, or a country with no module.
     */
    public function forRules(?Organization $organization): Jurisdiction
    {
        return $this->registry->orDefault(blank($organization?->country) ? null : (string) $organization->country);
    }

    /**
     * Whether the organization's country is registered and switched on, for
     * a screen that says so rather than refusing.
     */
    public function available(Organization $organization): bool
    {
        try {
            $this->forOrganization($organization);

            return true;
        } catch (JurisdictionUnavailableException) {
            return false;
        }
    }

    /**
     * @throws JurisdictionUnavailableException
     */
    public function forInvoice(Invoice $invoice): Jurisdiction
    {
        return $this->forOrganization(Organization::query()->findOrFail($invoice->org_id));
    }
}
