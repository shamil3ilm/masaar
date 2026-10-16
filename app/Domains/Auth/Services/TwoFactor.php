<?php

declare(strict_types=1);

namespace App\Domains\Auth\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Auth\Models\User;
use App\Domains\Auth\Support\Totp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * A platform admin's second factor: enrolment, the check at sign-in, and reset.
 *
 * Required of platform admins only. A platform admin can stop filing for every
 * tenant, suspend any account and change the thresholds the platform runs on,
 * and a password alone was all of that.
 */
class TwoFactor
{
    private const RECOVERY_CODES = 8;

    public function __construct(
        private readonly AuditService $audit,
        private readonly SignInThrottle $throttle,
    ) {}

    /**
     * Confirm enrolment with a code from the app, proving it holds the secret.
     *
     * @return list<string>|null The recovery codes, shown once; null when the code is wrong
     */
    public function confirm(User $user, string $secret, string $code): ?array
    {
        $step = Totp::verify($secret, $code);

        if ($step === null) {
            return null;
        }

        // Spent here, so it cannot sign in a second time within its window.
        $this->spendStep($user, $step);

        $codes = $this->newRecoveryCodes();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $codes,
            'two_factor_confirmed_at' => now(),
            // Remember-me cookies and API tokens from before were issued on
            // the password alone.
            'remember_token' => Str::random(60),
            'password_changed_at' => now(),
        ])->save();

        $this->audit->logSecurity('mfa.enabled', 'User', (string) $user->id, userId: (string) $user->id);

        return $codes;
    }

    /**
     * Whether a code from the app, or an unused recovery code, is good.
     *
     * A code is good for its whole thirty-second window, so the step it
     * belongs to is remembered and refused a second time - otherwise someone
     * who saw it typed could use it again within the window. A recovery code
     * is spent when it is used.
     */
    public function check(User $user, string $code): bool
    {
        $code = trim($code);

        if (! $user->hasTwoFactor()) {
            return false;
        }

        $step = Totp::verify((string) $user->two_factor_secret, $code);

        if ($step !== null) {
            if (! $this->spendStep($user, $step)) {
                $this->audit->logSecurity('mfa.replayed', 'User', (string) $user->id, userId: (string) $user->id);

                return false;
            }

            return true;
        }

        return $this->spendRecoveryCode($user, $code);
    }

    /**
     * Mark a code's step used, refusing it and every earlier one after.
     *
     * Under a lock: two requests with the same code at once must not both
     * read the last step before either writes it.
     */
    private function spendStep(User $user, int $step): bool
    {
        $key = 'mfa:last-step:'.$user->id;

        return (bool) Cache::lock($key.':lock', 5)->block(3, function () use ($key, $step): bool {
            if ($step <= (int) Cache::get($key, -1)) {
                return false;
            }

            Cache::put($key, $step, now()->addMinutes(5));

            return true;
        });
    }

    /**
     * The platform admin a pending sign-in names, while it is still fresh.
     *
     * @param  mixed  $pending  What SessionAuthController kept in the session
     */
    public function pendingAdmin(mixed $pending): ?User
    {
        if (! is_array($pending) || ($pending['until'] ?? 0) < now()->timestamp) {
            return null;
        }

        $user = User::query()->find($pending['id'] ?? null);

        return $user !== null && $user->isPlatformAdmin() ? $user : null;
    }

    /**
     * Whoever a pending sign-in names, while it is fresh: a platform admin,
     * or anyone else who has turned a second factor on.
     *
     * @param  mixed  $pending  What SessionAuthController kept in the session
     */
    public function pendingSignIn(mixed $pending): ?User
    {
        if (! is_array($pending) || ($pending['until'] ?? 0) < now()->timestamp) {
            return null;
        }

        $user = User::query()->find($pending['id'] ?? null);

        return $user !== null && $user->isActive() && ($user->isPlatformAdmin() || $user->hasTwoFactor()) ? $user : null;
    }

    /**
     * Whether this person may turn their second factor off: not a platform
     * admin, who must have one, nor a member of an organization requiring it.
     *
     * @return string|null Why not, or null when they may
     */
    public function mayTurnOff(User $user): ?string
    {
        if ($user->is_platform_admin) {
            return 'Platform admins keep two-factor on.';
        }

        $requiring = $user->activeOrganizations()->where('require_two_factor', true)->value('name');

        return $requiring === null ? null : $requiring.' requires two-factor for everyone in it.';
    }

    /**
     * Turn one's own second factor off, after proving it with a code.
     *
     * @throws \DomainException
     */
    public function turnOff(User $user, string $code): void
    {
        if (($refusal = $this->mayTurnOff($user)) !== null) {
            throw new \DomainException($refusal);
        }

        if (($lockedFor = $this->throttle->codeLockedFor($user)) > 0) {
            throw new \DomainException('Too many codes tried. Try again in '.$lockedFor.' seconds.');
        }

        if (! $this->check($user, $code)) {
            $this->throttle->codeFailed($user);

            throw new \DomainException('That code is not right.');
        }

        $this->throttle->codeSucceeded($user);
        $this->reset($user);

        $this->audit->logSecurity('mfa.disabled', 'User', (string) $user->id, userId: (string) $user->id);
    }

    /**
     * Clear another admin's second factor, for one who has lost their phone.
     *
     * @return string|null Why it was refused, or null when done
     */
    public function resetFor(string $userId, string $actorId): ?string
    {
        if ($userId === $actorId) {
            return 'Another platform admin has to reset your two-factor - that is the point of it.';
        }

        $user = User::query()->find($userId);

        if ($user === null) {
            return 'No such account.';
        }

        if (! User::query()->find($actorId)?->mayActOnAccessOf($user)) {
            return 'Your admin role cannot change the access of an admin of the same rank or above.';
        }

        if (! $user->hasTwoFactor()) {
            return 'This account has no two-factor to reset.';
        }

        $this->reset($user);

        return null;
    }

    /**
     * Clear a user's second factor, so they enrol again at their next sign-in.
     */
    public function reset(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            // And any enrolment link: one issued before the reset must not
            // come back to life with it.
            'two_factor_enrolment_hash' => null,
            'two_factor_enrolment_expires_at' => null,
        ])->save();
    }

    private function spendRecoveryCode(User $user, string $code): bool
    {
        $codes = (array) $user->two_factor_recovery_codes;

        foreach ($codes as $index => $stored) {
            if (hash_equals((string) $stored, strtolower($code))) {
                unset($codes[$index]);

                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                $this->audit->logSecurity('mfa.recovery_code_used', 'User', (string) $user->id, [
                    'remaining' => count($codes),
                ], userId: (string) $user->id);

                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function newRecoveryCodes(): array
    {
        $codes = [];

        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $codes[] = strtolower(Str::random(5).'-'.Str::random(5));
        }

        return $codes;
    }
}
