<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Services;

use App\Domains\Invoice\Models\Contract;
use App\Domains\Invoice\Models\DeliveryNote;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\BusinessTypes;

/**
 * Whether the portal menu offers summary invoices and contracts.
 *
 * Both bill a business: deliveries gathered into a month's invoice, a
 * contract billed by stages. The menu had 25 links, and a shop saw both. A
 * business whose type starts its invoices simplified (it sells over the
 * counter: a shop, a pharmacy, a school) is not offered them. A type that
 * bills businesses, a mixed one, or none keeps both, and an organization
 * that has delivery notes or contracts keeps the link to them whatever its
 * type. Only the link goes: the pages still open, as a type never refuses a
 * feature (BusinessTypes).
 */
class BillingMenu
{
    public function __construct(private readonly BusinessTypes $types) {}

    /**
     * @return array{summaries: bool, contracts: bool}
     */
    public function offers(?Organization $organization): array
    {
        if ($organization === null || $this->types->of($organization)?->invoiceType !== 'simplified') {
            return ['summaries' => true, 'contracts' => true];
        }

        // The portal's own organization: the tenant scope stands.
        return [
            'summaries' => DeliveryNote::query()->where('org_id', $organization->id)->exists(),
            'contracts' => Contract::query()->where('org_id', $organization->id)->exists(),
        ];
    }
}
