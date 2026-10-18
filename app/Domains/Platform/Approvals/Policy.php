<?php

declare(strict_types=1);

namespace App\Domains\Platform\Approvals;

/**
 * Whether an action waits for approval, how many approvers it needs, which
 * admin roles may approve it, and how long a request stays open.
 *
 * $implicit is the policy over the policies: on whenever any other is, and
 * not something an admin can switch off.
 */
final readonly class Policy
{
    /**
     * @param  list<string>  $approverRoles  admin role keys
     */
    public function __construct(
        public string $action,
        public bool $enabled,
        public int $approvalsRequired,
        public array $approverRoles,
        public int $expiresAfterHours,
        public bool $implicit = false,
    ) {}

    /**
     * @return array{enabled: bool, approvals_required: int, approver_roles: list<string>, expires_after_hours: int}
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'approvals_required' => $this->approvalsRequired,
            'approver_roles' => $this->approverRoles,
            'expires_after_hours' => $this->expiresAfterHours,
        ];
    }
}
