<?php

declare(strict_types=1);

namespace App\Domains\Platform\Approvals\Actions;

use App\Domains\Auth\Enums\AdminPermission;
use App\Domains\Auth\Models\User;
use App\Domains\Auth\Services\AccountStatus;
use App\Domains\Platform\Approvals\ActionResult;
use App\Domains\Platform\Approvals\ApprovableActions;
use App\Domains\Platform\Approvals\Preview;
use DomainException;

/**
 * Suspending a person's account, and letting them back in.
 */
final class AccountActions implements ApprovableActions
{
    public function __construct(
        private readonly AccountStatus $accounts,
    ) {}

    public function keys(): array
    {
        return ['users.suspend', 'users.reactivate'];
    }

    public function label(string $action): string
    {
        return $action === 'users.suspend' ? __('Suspend an account') : __('Reactivate an account');
    }

    public function permission(string $action): AdminPermission
    {
        return AdminPermission::UsersManage;
    }

    public function preview(string $action, array $payload, User $requester): Preview
    {
        $user = User::query()->find((string) $payload['user_id']) ?? throw new DomainException('No such account.');

        if ($action === 'users.suspend' && (string) $user->id === (string) $requester->id) {
            throw new DomainException('You cannot suspend your own account.');
        }

        if (! $requester->mayActOnAccessOf($user)) {
            throw new DomainException('Your admin role cannot change the access of an admin of the same rank or above.');
        }

        return $action === 'users.suspend'
            ? new Preview('Suspend '.$user->email.': '.$payload['reason'], 'User', (string) $user->id,
                ['status' => $user->status], ['status' => 'suspended', 'reason' => $payload['reason']], (string) $user->id)
            : new Preview('Reactivate '.$user->email, 'User', (string) $user->id,
                ['status' => $user->status], ['status' => 'active'], (string) $user->id);
    }

    public function execute(string $action, array $payload, User $requester): ActionResult
    {
        $refusal = $action === 'users.suspend'
            ? $this->accounts->suspend((string) $payload['user_id'], (string) $requester->id)
            : $this->accounts->reactivate((string) $payload['user_id']);

        if ($refusal !== null) {
            throw new DomainException($refusal);
        }

        return new ActionResult($action === 'users.suspend'
            ? 'Account suspended. Its sessions and tokens stop working on their next request.'
            : 'Account reactivated.');
    }
}
