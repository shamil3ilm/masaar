<?php

declare(strict_types=1);

namespace App\Domains\Organization\Services;

use App\Domains\Organization\Contracts\WorkingBranchStore;
use DomainException;

/**
 * The portal's branch switcher: the branches a person may choose between,
 * the one they chose, and choosing.
 *
 * "All branches" is null: everything they may see, which for a cashier is
 * their own branches and for an administrator every branch and head office.
 * A kept choice that is no longer allowed - the branch was removed, or they
 * were taken off it - is let go of the next time it is read.
 */
class WorkingBranches
{
    public function __construct(
        private readonly WorkingBranchStore $store,
        private readonly BranchAccess $access,
        private readonly BranchService $branches,
    ) {}

    /**
     * The branches offered, id => name, in till order.
     *
     * @return array<string, string>
     */
    public function options(string $userId, string $organizationId): array
    {
        $allowed = $this->access->allowed($userId, $organizationId);
        $options = [];

        foreach ($this->branches->tills($organizationId) as $till) {
            if ($allowed === null || in_array($till['id'], $allowed, true)) {
                $options[$till['id']] = $till['name'];
            }
        }

        return $options;
    }

    /** The chosen branch, if it is still one they may choose. */
    public function current(string $userId, string $organizationId): ?string
    {
        $chosen = $this->store->get($userId, $organizationId);

        if ($chosen === null) {
            return null;
        }

        if (! array_key_exists($chosen, $this->options($userId, $organizationId))) {
            $this->store->set($userId, $organizationId, null);

            return null;
        }

        return $chosen;
    }

    /**
     * Choose a branch, or all of them with null.
     *
     * @throws DomainException when it is not one they may choose
     */
    public function choose(string $userId, string $organizationId, ?string $branchId): void
    {
        if ($branchId !== null && ! array_key_exists($branchId, $this->options($userId, $organizationId))) {
            throw new DomainException(__('That branch is not one you work at.'));
        }

        $this->store->set($userId, $organizationId, $branchId);
    }
}
