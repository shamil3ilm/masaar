<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Domains\Compliance\Fatoora\Models\ChainEntry;
use App\Domains\Compliance\Fatoora\Models\ChainState;

/**
 * Where the current tenant's hash chain stands.
 *
 * Both models use BelongsToTenant, so these read the tenant the request
 * resolved and no other.
 */
class ChainReport
{
    /**
     * The counter value of the last recorded document, or 0 before the first.
     */
    public function latestIcv(): int
    {
        return ChainState::query()->value('last_icv') ?? 0;
    }

    /**
     * Whether the chain head points at the last entry actually recorded.
     *
     * A cheap consistency check, not a verification: VerifyHashChain walks the
     * entries themselves. A tenant with no chain yet is intact.
     */
    public function isIntact(): bool
    {
        $state = ChainState::query()->first();

        if (! $state) {
            return true;
        }

        $lastEntry = ChainEntry::query()->orderByDesc('icv')->first();

        if (! $lastEntry) {
            return false;
        }

        return $lastEntry->icv === $state->last_icv;
    }
}
