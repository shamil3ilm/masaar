<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\Contracts;

use App\Domains\Compliance\Engine\DTOs\Panel;

/**
 * A branch as a device the country's authority knows: whether it is
 * registered, and what it signs with.
 *
 * The branch screens every country shares ask for this beside a branch's
 * address, numbering and printer, and draw what comes back without knowing
 * what registering a device means where the organization is.
 */
interface Devices
{
    /**
     * Where one branch's device stands with the authority, as a part of the
     * branch's page; null where the country registers no device per branch.
     */
    public function branchStatus(string $organizationId, string $branchId): ?Panel;

    /**
     * Why an invoice issued from this branch now would be refused - its
     * device not yet registered, or suspended - in words a person can act
     * on; null when it can issue.
     */
    public function refusal(string $organizationId, string $branchId): ?string;
}
