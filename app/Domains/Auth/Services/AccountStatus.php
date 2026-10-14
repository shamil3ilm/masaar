<?php

declare(strict_types=1);

namespace App\Domains\Auth\Services;

use App\Domains\Auth\Models\User;

/**
 * Suspending an account and letting it back in.
 *
 * Suspension takes effect everywhere at once: the console and the API refuse
 * to sign the account in, JwtGuard refuses its tokens and ActiveAccount signs
 * out its sessions on their next request. Nothing here has to chase them.
 *
 * Each method answers with the reason it refused, or null when it did what
 * was asked. The audit is written by AuditAdminAction around the request, so
 * the reason an admin gave is in the record with everything else they sent.
 */
class AccountStatus
{
    public function suspend(string $userId, string $actorId): ?string
    {
        $user = User::query()->find($userId);

        if ($user === null) {
            return 'No such account.';
        }

        // An admin who suspends themselves has no console left to undo it in.
        if ((string) $user->id === $actorId) {
            return 'You cannot suspend your own account.';
        }

        if (! $user->isActive()) {
            return 'This account is already suspended.';
        }

        $user->update(['status' => 'suspended']);

        return null;
    }

    public function reactivate(string $userId): ?string
    {
        $user = User::query()->find($userId);

        if ($user === null) {
            return 'No such account.';
        }

        if ($user->isActive()) {
            return 'This account is already active.';
        }

        $user->update(['status' => 'active']);

        return null;
    }
}
