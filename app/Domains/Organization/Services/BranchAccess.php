<?php

declare(strict_types=1);

namespace App\Domains\Organization\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Auth\Models\User;
use App\Domains\Organization\Enums\MemberRole;
use App\Domains\Organization\Models\BranchAssignment;
use DomainException;

/**
 * Which branches a person works at, and what they may do at each.
 *
 * By their role in the organization (MemberRole): an owner or admin works
 * at every branch; an accountant sees every branch, read only. A member or
 * viewer works at every branch until they are assigned to one or more; from
 * then on they see and issue at those alone - a cashier at their till, an
 * auditor shown one shop's books. The assignment carries what they may do
 * there: issue and correct (member), or read (viewer), and it never lifts
 * what their role in the organization allows: a read-only role reads at
 * every branch it sees.
 *
 * Who counts as seeing every branch is decided in one place,
 * seesEveryBranch().
 */
class BranchAccess
{
    /** What a person may do at a branch they are assigned to. */
    public const ROLES = ['member', 'viewer'];

    /** Organization roles that see every branch, assigned or not: the accountant's read only. */
    private const EVERY_BRANCH = [MemberRole::Owner->value, MemberRole::Admin->value, MemberRole::Accountant->value];

    public function __construct(
        private readonly BranchService $branches,
        private readonly AuditService $audit,
    ) {}

    /**
     * The branches a person may see in an organization: null for every one,
     * otherwise the ids of those they are assigned to. Someone who is not a
     * member sees none.
     *
     * @return list<string>|null
     */
    public function allowed(string $userId, string $organizationId): ?array
    {
        $role = $this->organizationRole($userId, $organizationId);

        if ($role === null) {
            return [];
        }

        if ($this->seesEveryBranch($role)) {
            return null;
        }

        $assigned = array_keys($this->assignmentsOf($userId, $organizationId));

        return $assigned === [] ? null : $assigned;
    }

    /**
     * What a person may do at one branch - admin, member or viewer - or null
     * when they may not work there. A null branch is head office, which only
     * someone working at every branch reaches.
     */
    public function roleAt(string $userId, string $organizationId, ?string $branchId): ?string
    {
        $role = $this->organizationRole($userId, $organizationId);

        if ($role === null) {
            return null;
        }

        $allowed = $this->allowed($userId, $organizationId);

        if ($allowed === null) {
            return $role;
        }

        if ($branchId === null || ! in_array($branchId, $allowed, true)) {
            return null;
        }

        $atBranch = $this->assignmentsOf($userId, $organizationId)[$branchId];

        // The narrower of the two: a read-only role in the organization
        // reads wherever they are assigned, whatever the assignment says.
        return MemberRole::readOnly($role) ? MemberRole::Viewer->value : $atBranch;
    }

    /** Whether a person may issue, correct and send at a branch: never a read-only role. */
    public function mayWriteAt(string $userId, string $organizationId, ?string $branchId): bool
    {
        $role = $this->roleAt($userId, $organizationId, $branchId);

        return $role !== null && ! MemberRole::readOnly($role);
    }

    /**
     * The branch a new invoice is issued from: the one asked for, which the
     * person must be able to issue at, or - when none is asked for - their
     * own branch if they work at only some, or head office (null) if they
     * work at every one.
     *
     * @throws DomainException when they may not issue at the branch
     */
    public function draftBranch(string $userId, string $organizationId, ?string $requested): ?string
    {
        $allowed = $this->allowed($userId, $organizationId);
        $branch = $requested ?? ($allowed === null ? null : ($allowed[0] ?? null));

        if ($branch !== null && ! $this->branches->belongsTo($branch, $organizationId)) {
            throw new DomainException(__('That branch is not one of this organization\'s.'));
        }

        // A read-only role issues nowhere, though it sees every branch.
        if (($allowed !== null || MemberRole::readOnly($this->organizationRole($userId, $organizationId))) && ! $this->mayWriteAt($userId, $organizationId, $branch)) {
            throw new DomainException(__('You cannot issue invoices at that branch.'));
        }

        return $branch;
    }

    /**
     * Who is assigned to a branch, and as what.
     *
     * @return array<string, string> user id => role
     */
    public function assignments(string $organizationId, string $branchId): array
    {
        return BranchAssignment::acrossTenants()
            ->where('org_id', $organizationId)
            ->where('branch_id', $branchId)
            ->pluck('role', 'user_id')
            ->map(fn (mixed $role): string => (string) $role)
            ->all();
    }

    /**
     * How many people are assigned to each of the organization's branches.
     *
     * @return array<string, int> branch id => people
     */
    public function counts(string $organizationId): array
    {
        return BranchAssignment::acrossTenants()
            ->where('org_id', $organizationId)
            ->selectRaw('branch_id, COUNT(*) as people')
            ->groupBy('branch_id')
            ->pluck('people', 'branch_id')
            ->map(fn (mixed $people): int => (int) $people)
            ->all();
    }

    /**
     * Put a member at a branch as member or viewer, or take them off it with
     * a null role. Each change is on the organization's activity record.
     *
     * @throws DomainException when the person or the branch is not the organization's
     */
    public function assign(string $organizationId, string $branchId, string $userId, ?string $role): void
    {
        if (! $this->branches->belongsTo($branchId, $organizationId)) {
            throw new DomainException(__('That branch is not one of this organization\'s.'));
        }

        if ($this->organizationRole($userId, $organizationId) === null) {
            throw new DomainException(__('That person is not a member of this organization.'));
        }

        if ($role !== null && ! in_array($role, self::ROLES, true)) {
            throw new DomainException(__('A person works at a branch as a member or a viewer.'));
        }

        $before = $this->assignmentsOf($userId, $organizationId)[$branchId] ?? null;

        if ($before === $role) {
            return;
        }

        if ($role === null) {
            // Through the model, so who took them off is kept.
            BranchAssignment::acrossTenants()->where('org_id', $organizationId)->where('branch_id', $branchId)->where('user_id', $userId)->first()?->delete();
        } else {
            // Someone taken off and put back gets their row again.
            $assignment = BranchAssignment::acrossTenants()->withTrashed()
                ->where('org_id', $organizationId)
                ->where('branch_id', $branchId)
                ->where('user_id', $userId)
                ->first()
                ?? new BranchAssignment(['org_id' => $organizationId, 'branch_id' => $branchId, 'user_id' => $userId]);

            $assignment->role = $role;
            $assignment->deleted_at = null;
            $assignment->save();
        }

        $this->audit->logSecurity('member.branch_assigned', 'Branch', $branchId, [
            'user_id' => $userId,
            'from' => $before,
            'to' => $role,
        ]);
    }

    /**
     * The one place that decides who sees every branch: an owner, an admin
     * and an accountant (read only, mayWriteAt()); a member or viewer only
     * where assigned, or everywhere while assigned nowhere (allowed()).
     */
    private function seesEveryBranch(string $role): bool
    {
        return in_array($role, self::EVERY_BRANCH, true);
    }

    /**
     * @return array<string, string> branch id => role, in the order they were assigned
     */
    private function assignmentsOf(string $userId, string $organizationId): array
    {
        return BranchAssignment::acrossTenants()
            ->where('org_id', $organizationId)
            ->where('user_id', $userId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->pluck('role', 'branch_id')
            ->map(fn (mixed $role): string => (string) $role)
            ->all();
    }

    private function organizationRole(string $userId, string $organizationId): ?string
    {
        return User::query()->find($userId)?->roleIn($organizationId);
    }
}
