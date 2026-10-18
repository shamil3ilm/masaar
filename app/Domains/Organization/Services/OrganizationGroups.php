<?php

declare(strict_types=1);

namespace App\Domains\Organization\Services;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Models\OrganizationGroup;
use Illuminate\Support\Collection;

/**
 * Several companies of one group, as one person sees them.
 *
 * Only the companies of the group the person is an active member of: being
 * in one company of a group shows nothing of the others. Each company stays
 * a taxpayer of its own - its own invoices, numbering, filing and return -
 * and the group's view only reads them side by side.
 */
class OrganizationGroups
{
    /**
     * The group the organization belongs to, when the person belongs to at
     * least one other company of it; otherwise null.
     */
    public function groupFor(User $user, string $organizationId): ?OrganizationGroup
    {
        $organization = Organization::query()->find($organizationId);

        if ($organization?->group_id === null || $this->companies($user, $organizationId)->count() < 2) {
            return null;
        }

        return OrganizationGroup::query()->find($organization->group_id);
    }

    /**
     * The companies of the organization's group the person actively belongs
     * to, by name - the organization itself among them.
     *
     * @return Collection<int, Organization>
     */
    public function companies(User $user, string $organizationId): Collection
    {
        $groupId = Organization::query()->whereKey($organizationId)->value('group_id');

        if ($groupId === null) {
            return new Collection;
        }

        return $user->activeOrganizations()
            ->where('organizations.group_id', $groupId)
            ->orderBy('organizations.name')
            ->get(['organizations.id', 'organizations.name', 'organizations.country', 'organizations.group_id']);
    }
}
