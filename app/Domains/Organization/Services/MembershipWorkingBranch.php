<?php

declare(strict_types=1);

namespace App\Domains\Organization\Services;

use App\Domains\Organization\Contracts\WorkingBranchStore;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The switcher's choice, kept on the membership beside the person's own
 * paper (PrintPreferences), until the layered settings store keeps
 * per-person preferences.
 */
class MembershipWorkingBranch implements WorkingBranchStore
{
    public function get(string $userId, string $organizationId): ?string
    {
        $branch = $this->membership($userId, $organizationId)->value('working_branch_id');

        return is_string($branch) ? $branch : null;
    }

    public function set(string $userId, string $organizationId, ?string $branchId): void
    {
        $this->membership($userId, $organizationId)->update(['working_branch_id' => $branchId]);
    }

    private function membership(string $userId, string $organizationId): Builder
    {
        return DB::table('organization_user')->where('user_id', $userId)->where('org_id', $organizationId);
    }
}
