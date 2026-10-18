<?php

declare(strict_types=1);

namespace App\Domains\Platform\Approvals;

use App\Domains\Auth\Enums\PlatformRole;
use App\Domains\Platform\Models\ApprovalPolicy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Which admin actions wait for approval, and on whose say.
 *
 * Everything ships off except changing who is an admin and in which role:
 * that is how one stolen owner account would make itself more. A policy row
 * exists only where an admin changed the shipped default.
 *
 * approvals.policies is the policy over the policies. It is on whenever any
 * other policy is, and cannot be switched off: otherwise the first thing to
 * do with a stolen account would be to turn approvals off.
 */
class ApprovalPolicies
{
    public const POLICIES = 'approvals.policies';

    /** On as shipped. */
    public const RISKY = ['admins.grant', 'admins.role', 'admins.revoke'];

    public const MAX_APPROVALS = 3;

    public const DEFAULT_HOURS = 72;

    public const MAX_HOURS = 720;

    private const CACHE_KEY = 'approvals.policies';

    public function __construct(
        private readonly ApprovalCatalogue $catalogue,
    ) {}

    /**
     * Every action's policy, by action, in the catalogue's order.
     *
     * @return array<string, Policy>
     */
    public function all(): array
    {
        $rows = Cache::rememberForever(self::CACHE_KEY, fn (): array => ApprovalPolicy::query()->get()
            ->mapWithKeys(fn (ApprovalPolicy $row): array => [$row->action => [
                'enabled' => $row->enabled,
                'approvals_required' => $row->approvals_required,
                'approver_roles' => array_values((array) $row->approver_roles),
                'expires_after_hours' => $row->expires_after_hours,
            ]])->all());

        $policies = [];

        foreach ($this->catalogue->keys() as $action) {
            if ($action === self::POLICIES) {
                continue;
            }

            $row = $rows[$action] ?? null;

            $policies[$action] = new Policy(
                action: $action,
                enabled: $row['enabled'] ?? in_array($action, self::RISKY, true),
                approvalsRequired: $row['approvals_required'] ?? 1,
                approverRoles: $row['approver_roles'] ?? [PlatformRole::Owner->value],
                expiresAfterHours: $row['expires_after_hours'] ?? self::DEFAULT_HOURS,
            );
        }

        $any = array_filter($policies, fn (Policy $policy): bool => $policy->enabled) !== [];
        $own = $rows[self::POLICIES] ?? null;

        return [...$policies, self::POLICIES => new Policy(
            action: self::POLICIES,
            enabled: $any,
            approvalsRequired: $own['approvals_required'] ?? 1,
            approverRoles: $own['approver_roles'] ?? [PlatformRole::Owner->value],
            expiresAfterHours: $own['expires_after_hours'] ?? self::DEFAULT_HOURS,
            implicit: true,
        )];
    }

    public function get(string $action): Policy
    {
        return $this->all()[$action] ?? throw new \InvalidArgumentException("No approval policy for {$action}.");
    }

    /**
     * The values a policy form posted, checked: the policy page and the
     * approval that later applies them read the same thing.
     *
     * @param  array<string, mixed>  $input
     * @return array{enabled: bool, approvals_required: int, approver_roles: list<string>, expires_after_hours: int}
     *
     * @throws ValidationException
     */
    public function clean(string $action, array $input): array
    {
        $roles = array_values(array_unique(array_map('strval', (array) ($input['approver_roles'] ?? []))));
        $known = $this->roleKeys();
        $approvals = filter_var($input['approvals_required'] ?? null, FILTER_VALIDATE_INT);
        $hours = filter_var($input['expires_after_hours'] ?? null, FILTER_VALIDATE_INT);
        $problems = [];

        if (! $this->catalogue->has($action)) {
            $problems['action'] = __('There is no such action.');
        }

        if ($roles === [] || array_diff($roles, $known) !== []) {
            $problems['approver_roles'] = __('Choose at least one admin role that may approve.');
        }

        if ($approvals === false || $approvals < 1 || $approvals > self::MAX_APPROVALS) {
            $problems['approvals_required'] = __('Between 1 and :max approvers.', ['max' => self::MAX_APPROVALS]);
        }

        if ($hours === false || $hours < 1 || $hours > self::MAX_HOURS) {
            $problems['expires_after_hours'] = __('Between 1 and :max hours.', ['max' => self::MAX_HOURS]);
        }

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }

        return [
            // The policy over the policies follows the others; its switch is
            // not its own.
            'enabled' => $action === self::POLICIES ? true : filter_var($input['enabled'] ?? false, FILTER_VALIDATE_BOOL),
            'approvals_required' => (int) $approvals,
            'approver_roles' => $roles,
            'expires_after_hours' => (int) $hours,
        ];
    }

    /**
     * @param  array{enabled: bool, approvals_required: int, approver_roles: list<string>, expires_after_hours: int}  $values
     */
    public function save(string $action, array $values, ?string $actorId): void
    {
        DB::transaction(fn () => ApprovalPolicy::query()->updateOrCreate(['action' => $action], [...$values, 'updated_by' => $actorId]));

        Cache::forget(self::CACHE_KEY);
        DB::afterCommit(fn () => Cache::forget(self::CACHE_KEY));
    }

    /**
     * The admin roles a policy can name as approvers.
     *
     * @return list<string>
     */
    public function roleKeys(): array
    {
        return array_map(fn (PlatformRole $role): string => $role->value, PlatformRole::cases());
    }
}
