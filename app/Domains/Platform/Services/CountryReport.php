<?php

declare(strict_types=1);

namespace App\Domains\Platform\Services;

use App\Domains\Billing\Services\Billing;
use App\Domains\Compliance\Engine\Services\JurisdictionAvailability;
use App\Domains\Compliance\Engine\Services\JurisdictionRegistry;
use App\Domains\Organization\Models\Organization;

/**
 * Every country the platform knows, as the admin console's Countries page
 * shows it: on or off and why, how many organizations it would affect, and
 * what each module says about being ready.
 *
 * One row per registered module, from the registry, so a country added later
 * appears here without this file changing.
 */
final class CountryReport
{
    public function __construct(
        private readonly JurisdictionRegistry $registry,
        private readonly JurisdictionAvailability $availability,
        private readonly Billing $billing,
    ) {}

    /**
     * @return list<array{code: string, name: string, system: string, enabled: bool, switch: array{enabled: bool, by: string|null, at: string|null}|null, organizations: int, readiness: array<string, string>, billing_problems: list<string>, billing: bool}>
     */
    public function rows(): array
    {
        $organizations = $this->organizationsByCountry();
        $billingCountry = $this->billingCountry();
        $rows = [];

        foreach ($this->registry->registered() as $code) {
            $jurisdiction = $this->registry->get($code);

            $rows[] = [
                'code' => $code,
                'name' => $jurisdiction->name(),
                'system' => $jurisdiction->filingSystem(),
                'enabled' => $jurisdiction->enabled(),
                'switch' => $this->availability->switched($code),
                'organizations' => $organizations[$code] ?? 0,
                'readiness' => $jurisdiction->readiness(),
                'billing_problems' => $billingCountry === $code ? $this->billing->readiness() : [],
                'billing' => $billingCountry === $code,
            ];
        }

        return $rows;
    }

    /**
     * Organizations per country. A blank country is a row from before the
     * column was set, and counts where the engine resolves it: the default.
     *
     * @return array<string, int>
     */
    private function organizationsByCountry(): array
    {
        $counts = [];
        $rows = Organization::query()
            ->selectRaw('country, count(*) as total')
            ->groupBy('country')
            ->get();

        foreach ($rows as $row) {
            $code = blank($row->country) ? $this->registry->defaultCode() : (string) $row->country;
            $counts[$code] = ($counts[$code] ?? 0) + (int) $row->getAttribute('total');
        }

        return $counts;
    }

    /**
     * The country of the organization the platform bills its customers from
     * (BILLING_ORGANIZATION_ID), whose invoices stop when it is switched off.
     */
    private function billingCountry(): ?string
    {
        $organization = $this->billing->billingOrganization();

        if ($organization === null) {
            return null;
        }

        return blank($organization->country) ? $this->registry->defaultCode() : (string) $organization->country;
    }
}
