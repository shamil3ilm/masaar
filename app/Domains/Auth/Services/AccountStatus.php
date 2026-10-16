<?php

declare(strict_types=1);

namespace App\Domains\Auth\Services;

use App\Domains\Auth\Enums\PlatformRole;
use App\Domains\Auth\Models\User;
use Illuminate\Support\Facades\Password;

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

        if (! User::query()->find($actorId)?->mayActOnAccessOf($user)) {
            return 'Your admin role cannot change the access of an admin of the same rank or above.';
        }

        // The last owner suspended leaves nobody to manage admins.
        if ($user->platformRole() === PlatformRole::Owner && ! $this->anotherOwner($user)) {
            return 'This is the only active owner. Make someone else an owner first.';
        }

        $user->update(['status' => 'suspended']);

        return null;
    }

    /**
     * Email someone a link to choose a new password - the support answer to
     * "I cannot sign in". The admin never sees or sets the password.
     */
    public function sendPasswordReset(string $userId, string $actorId): ?string
    {
        $user = User::query()->find($userId);

        if ($user === null) {
            return 'No such account.';
        }

        if (! $user->isActive()) {
            return 'This account is suspended. Reactivate it first.';
        }

        if ((string) $user->id !== $actorId && ! User::query()->find($actorId)?->mayActOnAccessOf($user)) {
            return 'Your admin role cannot change the access of an admin of the same rank or above.';
        }

        $status = Password::broker()->sendResetLink(['email' => $user->email]);

        return $status === Password::RESET_LINK_SENT ? null : __($status);
    }

    private function anotherOwner(User $leaving): bool
    {
        return User::query()
            ->where('is_platform_admin', true)
            ->where('status', 'active')
            ->whereKeyNot($leaving->getKey())
            ->where(fn ($query) => $query->where('platform_role', PlatformRole::Owner->value)->orWhereNull('platform_role'))
            ->exists();
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
