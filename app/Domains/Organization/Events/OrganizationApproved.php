<?php

declare(strict_types=1);

namespace App\Domains\Organization\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A platform admin approved a business that signed itself up: it has its
 * plan, and may onboard and issue. Dispatched once the decision is
 * committed.
 */
final class OrganizationApproved
{
    use Dispatchable;

    public function __construct(
        public readonly string $organizationId,
        public readonly string $adminId,
    ) {}
}
