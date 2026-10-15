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
    ) {}

    /**
     * Confirm enrolment with a code from the app, proving it holds the secret.
     *
     * @return list<string>|null The recovery codes, shown once; null when the code is wrong
     */
    public function confirm(User $user, string $secret, string $code): ?array
    {
        if (Totp::verify($secret, $code) === null) {
            return null;
        }

        $codes = $this->newRecoveryCodes();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $codes,
            'two_factor_confirmed_at' => now(),
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
            $key = 'mfa:last-step:'.$user->id;

            if ($step <= (int) Cache::get($key, -1)) {
                $this->audit->logSecurity('mfa.replayed', 'User', (string) $user->id, userId: (string) $user->id);

                return false;
            }

            Cache::put($key, $step, now()->addMinutes(5));

            return true;
        }

        return $this->spendRecoveryCode($user, $code);
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
