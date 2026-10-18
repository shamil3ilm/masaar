<?php

declare(strict_types=1);

namespace App\Domains\Organization\Services;

use App\Domains\Organization\Contracts\WorkingBranchStore;
use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;

/**
 * The switcher's choice as a person's own customization in one organization
 * (branches.working, Level::User): no platform or organization default, by
 * design - which branch a person works at is theirs alone. None is every
 * branch they may see; WorkingBranches checks a choice is still allowed.
 */
class CustomizedWorkingBranch implements WorkingBranchStore
{
    private const KEY = 'branches.working';

    public function __construct(
        private readonly Customizations $customizations,
    ) {}

    public function get(string $userId, string $organizationId): ?string
    {
        $branch = $this->customizations->own(self::KEY, Level::User, Scope::member($organizationId, $userId));

        return is_string($branch) ? $branch : null;
    }

    public function set(string $userId, string $organizationId, ?string $branchId): void
    {
        $scope = Scope::member($organizationId, $userId);

        $branchId === null
            ? $this->customizations->reset(self::KEY, Level::User, $scope, $userId)
            : $this->customizations->set(self::KEY, Level::User, $scope, $branchId, $userId);
    }
}
