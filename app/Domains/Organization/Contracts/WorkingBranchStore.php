<?php

declare(strict_types=1);

namespace App\Domains\Organization\Contracts;

/**
 * Where a person's choice in the portal's branch switcher is kept: the
 * branch they work at in one organization, or null for all of them.
 *
 * A per-person preference, behind an interface so the layered settings
 * store can keep it instead by binding another implementation.
 */
interface WorkingBranchStore
{
    public function get(string $userId, string $organizationId): ?string;

    public function set(string $userId, string $organizationId, ?string $branchId): void;
}
