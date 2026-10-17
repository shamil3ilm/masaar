<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Reports;

use App\Domains\Compliance\Countries\SaudiArabia\Models\ChainEntry;
use App\Domains\Compliance\Countries\SaudiArabia\Models\ChainState;

/**
 * Where the current tenant's hash chain stands.
 *
 * Both models use BelongsToTenant, so these read the tenant the request
 * resolved and no other.
 */
class ChainReport
{
    /**
     * The furthest any of the tenant's devices has counted, or 0 before the
     * first document. For a tenant with one device - most of them - that
     * device's counter.
     */
    public function latestIcv(): int
    {
        return (int) (ChainState::query()->max('last_icv') ?? 0);
    }

    /**
     * Whether every device's chain head points at the last entry actually
     * recorded for that device.
     *
     * A cheap consistency check, not a verification: VerifyHashChain walks the
     * entries themselves. A tenant with no chain yet is intact. It compared
     * one head with the tenant's highest entry, which with more than one
     * device compares one device's head with another's entry.
     */
    public function isIntact(): bool
    {
        foreach (ChainState::query()->get() as $state) {
            $lastIcv = ChainEntry::query()->where('device_id', $state->device_id)->max('icv');

            if ($lastIcv === null || (int) $lastIcv !== $state->last_icv) {
                return false;
            }
        }

        return true;
    }
}
