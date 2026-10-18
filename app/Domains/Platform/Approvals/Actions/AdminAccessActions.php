<?php

declare(strict_types=1);

namespace App\Domains\Platform\Approvals\Actions;

use App\Domains\Auth\Enums\AdminPermission;
use App\Domains\Auth\Enums\PlatformRole;
use App\Domains\Auth\Models\User;
use App\Domains\Auth\Services\PlatformAdmins;
use App\Domains\Platform\Approvals\ActionResult;
use App\Domains\Platform\Approvals\ApprovableActions;
use App\Domains\Platform\Approvals\Preview;
use DomainException;

/**
 * Who is a platform admin, and in which role: granting, changing and
 * revoking. On as shipped - it is how one stolen account would make itself
 * more.
 */
final class AdminAccessActions implements ApprovableActions
{
    public function __construct(
        private readonly PlatformAdmins $admins,
    ) {}

    public function keys(): array
    {
        return ['admins.grant', 'admins.role', 'admins.revoke'];
    }

    public function label(string $action): string
    {
        return match ($action) {
            'admins.grant' => __('Make someone a platform admin'),
            'admins.role' => __('Change an admin\'s role'),
            default => __('Remove an admin\'s access'),
        };
    }

    public function permission(string $action): AdminPermission
    {
        return AdminPermission::AdminsManage;
    }

    public function preview(string $action, array $payload, User $requester): Preview
    {
        if ($action === 'admins.grant') {
            $user = User::query()->where('email', (string) $payload['email'])->first();

            return new Preview('Make '.$payload['email'].' an admin as '.$payload['role'], 'User', $user?->id,
                ['admin' => (bool) $user?->is_platform_admin], ['admin' => true, 'role' => $payload['role']], $user?->id);
        }

        if ((string) $payload['user_id'] === (string) $requester->id) {
            throw new DomainException('Another owner has to change your own access.');
        }

        $user = User::query()->whereKey((string) $payload['user_id'])->where('is_platform_admin', true)->first()
            ?? throw new DomainException('That account is not an admin.');
        $before = ['role' => $user->platform_role ?? PlatformRole::Owner->value];

        return $action === 'admins.role'
            ? new Preview('Change '.$user->email.'\'s role to '.$payload['role'], 'User', (string) $user->id, $before, ['role' => $payload['role']], (string) $user->id)
            : new Preview('Remove '.$user->email.'\'s admin access', 'User', (string) $user->id, $before, ['admin' => false], (string) $user->id);
    }

    public function execute(string $action, array $payload, User $requester): ActionResult
    {
        return match ($action) {
            'admins.grant' => $this->grant($payload, $requester),
            'admins.role' => $this->changeRole($payload, $requester),
            default => $this->revoke($payload, $requester),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function grant(array $payload, User $requester): ActionResult
    {
        $link = $this->admins->grant((string) $payload['email'], (string) $payload['role'], $requester);

        return new ActionResult(
            $payload['email'].' is now an admin.'.($link ? ' Send them the enrolment link below; they set up two-factor before they can sign in.' : ''),
            ['enrolment_link' => $link],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function changeRole(array $payload, User $requester): ActionResult
    {
        $this->admins->changeRole((string) $payload['user_id'], (string) $payload['role'], $requester);

        return new ActionResult('Role changed.');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function revoke(array $payload, User $requester): ActionResult
    {
        $this->admins->revoke((string) $payload['user_id'], $requester);

        return new ActionResult('Admin access removed.');
    }
}
