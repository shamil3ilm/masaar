<?php

declare(strict_types=1);

namespace App\Domains\Organization\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A branch was removed: dispatched by BranchService::delete once the row is
 * gone. What a country keeps for the branch's device outside the database -
 * the credentials it signs with - is the country module's to clear, so it
 * listens for this rather than the branch screens naming its store.
 */
final class BranchRemoved
{
    use Dispatchable;

    public function __construct(
        public readonly string $organizationId,
        public readonly string $branchId,
    ) {}
}
