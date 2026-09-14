<?php

declare(strict_types=1);

namespace App\Domains\Organization\Services;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * Who belongs to which organization.
 *
 * Users are not tenant-scoped, because one person can belong to several
 * organizations, so the global scope cannot decide who is visible. These walk
 * the active-membership pivot explicitly instead.
 */
class Membership
{
    /**
     * Organizations the user actively belongs to, by name.
     */
    public function organizations(User $user): Collection
    {
        return $user->activeOrganizations()
            ->orderBy('name')
            ->get(['organizations.id', 'organizations.name', 'organizations.vat_number', 'organizations.status']);
    }

    /**
     * Organizations the user actively belongs to, each with the membership on
     * its pivot.
     *
     * A removed membership grants nothing, not even knowing the organization.
     */
    public function memberships(User $user): Collection
    {
        return $user->activeOrganizations()->get();
    }

    /**
     * One organization the user actively belongs to, with the membership on
     * its pivot.
     *
     * @throws ModelNotFoundException when they do not belong to it
     */
    public function organization(User $user, string $organizationId): Organization
    {
        return $user->activeOrganizations()->findOrFail($organizationId);
    }

    /**
     * Active members of one organization, by name, with only their id, name
     * and email.
     *
     * @return Collection<int, User>
     */
    public function members(string $organizationId): Collection
    {
        return $this->ofOrganization($organizationId)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    /**
     * One active member of an organization, or null when the user is not one.
     */
    public function member(string $organizationId, string $userId): ?User
    {
        return $this->ofOrganization($organizationId)
            ->whereKey($userId)
            ->first(['id', 'name', 'email']);
    }

    private function ofOrganization(string $organizationId): Builder
    {
        return User::query()
            ->whereHas('activeOrganizations', fn ($q) => $q->whereKey($organizationId));
    }
}
