<?php

declare(strict_types=1);

namespace App\Domains\Platform\Approvals;

use App\Domains\Auth\Models\User;

/**
 * A family whose actions a customer asks for in the portal and a platform
 * admin approves: a production API key, say.
 *
 * Such a requester holds no admin permission, so what is checked again when
 * the approved action runs is theirs to answer - still an administrator of
 * the organization it is for - instead of permission().
 */
interface RequestedByCustomers
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function requesterMayStill(string $action, array $payload, User $requester): bool;
}
