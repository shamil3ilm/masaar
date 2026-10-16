<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Peppol\Services;

use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\TenantResolver;

/**
 * Whether the portal's UAE screens are open to the organization in session.
 *
 * Only on a deployment that offers UAE e-invoicing, and only to an
 * organization registered in the UAE. Anywhere else the screens answer as
 * if they did not exist, as the API does (UaeInvoicing): a Saudi
 * organization's invoices are filed by its own country's rules.
 */
class UaePortal
{
    public function __construct(
        private readonly TenantResolver $tenant,
    ) {}

    public static function offers(?Organization $organization): bool
    {
        return (bool) config('peppol.countries.AE.enabled') && $organization?->country === 'AE';
    }

    /**
     * The organization in session, or a 404 when the UAE screens are not
     * open to it.
     */
    public function organization(): Organization
    {
        $organization = $this->tenant->getOrganization();

        abort_unless($organization !== null && self::offers($organization), 404);

        return $organization;
    }
}
