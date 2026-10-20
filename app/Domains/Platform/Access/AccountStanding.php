<?php

declare(strict_types=1);

namespace App\Domains\Platform\Access;

use App\Domains\Organization\Models\Organization;

/**
 * Where an organization's account with the platform stands: the port
 * through which Access learns of the overdue-payment freeze, which billing
 * decides (Billing\Services\Freeze) - by its policy, the customer's own,
 * and a platform admin's hand.
 */
interface AccountStanding
{
    public function of(Organization $organization): Standing;
}
