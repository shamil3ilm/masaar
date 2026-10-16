<?php

declare(strict_types=1);

namespace App\Domains\Auth\Services;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Auth\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One-time links that permit a platform admin to enrol a second factor.
 *
 * Enrolment was offered to anyone who signed in with an unenrolled admin's
 * password, so the password alone was enough to bind an attacker's
 * authenticator to the account - before the admin's first sign-in, or in the
 * window after another admin reset theirs. Now enrolment needs the password
 * and a link only another admin, or someone at the server's console, can
 * issue.
 *
 * The token is stored hashed, expires, and is spent by the enrolment it
 * permits. Issuing another replaces the last.
 */
class EnrolmentLinks
{
    public const VALID_HOURS = 24;

    /** enrol(): the link was spent, replaced or expired meanwhile. */
    public const LINK_GONE = 'link_gone';

    /** enrol(): the code did not match; the link is kept for another try. */
    public const WRONG_CODE = 'wrong_code';

    public function __construct(
        private readonly AuditService $audit,
        private readonly TwoFactor $twoFactor,
    ) {}

    /**
     * Spend the link and confirm the factor, together.
     *
     * Checked and then spent afterwards, two browsers holding the same link
     * could both pass the check, and the second would silently replace the
     * first one's secret. A wrong code rolls the claim back, so the link
     * still works for the next try.
     *
     * \return list<string>|self::LINK_GONE|self::WRONG_CODE The recovery codes, or why not
     */
    public function enrol(User $user, mixed $hash, string $secret, string $code): array|string
    {
        DB::beginTransaction();

        try {
            if (! $this->claim($user, $hash)) {
                DB::rollBack();

                return self::LINK_GONE;
            }

            $codes = $this->twoFactor->confirm($user, $secret, $code);

            if ($codes === null) {
                DB::rollBack();

                return self::WRONG_CODE;
            }

            DB::commit();

            return $codes;
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }
    }

    /**
     * A link for a user, replacing any earlier one.
     *
     * @param  string|null  $actorId  The admin who asked; null from the command line
     */
    public function issue(User $user, ?string $actorId): string
    {
        $token = Str::random(48);

        $user->forceFill([
            'two_factor_enrolment_hash' => self::hash($token),
            'two_factor_enrolment_expires_at' => now()->addHours(self::VALID_HOURS),
        ])->save();

        $this->audit->logSecurity('mfa.enrolment_link_issued', 'User', (string) $user->id, [
            'issued_by' => $actorId ?? 'console',
        ], userId: $actorId);

        return route('login.two-factor.enrol', $token);
    }

    /**
     * A link for another admin, from the console.
     *
     * @return array{0: string|null, 1: string|null} [why it was refused, the link]
     */
    public function issueFor(string $userId, string $actorId): array
    {
        if ($userId === $actorId) {
            return ['Another platform admin has to issue your enrolment link - that is the point of it.', null];
        }

        $user = User::query()->find($userId);

        if ($user === null) {
            return ['No such account.', null];
        }

        if (! $user->isPlatformAdmin()) {
            return ['Only platform admins enrol a second factor.', null];
        }

        if (! User::query()->find($actorId)?->mayActOnAccessOf($user)) {
            return ['Your admin role cannot change the access of an admin of the same rank or above.', null];
        }

        if ($user->hasTwoFactor()) {
            return ['This account already has two-factor. Reset it first if they have lost it.', null];
        }

        return [null, $this->issue($user, $actorId)];
    }

    /**
     * The admin a link was issued to, while it is unspent and fresh.
     */
    public function holder(string $token): ?User
    {
        return $this->holderOfHash(self::hash($token));
    }

    /**
     * Whether a link's hash, as kept in the session, still permits this
     * admin to enrol.
     */
    public function permits(User $user, mixed $hash): bool
    {
        return is_string($hash) && $this->holderOfHash($hash)?->is($user) === true;
    }

    /**
     * Spend a link, if it is still this admin's and still live, in one
     * conditional write: of two requests holding the same link, one wins.
     */
    public function claim(User $user, mixed $hash): bool
    {
        if (! is_string($hash)) {
            return false;
        }

        return User::query()
            ->whereKey($user->getKey())
            ->where('two_factor_enrolment_hash', $hash)
            ->where('two_factor_enrolment_expires_at', '>', now())
            ->update([
                'two_factor_enrolment_hash' => null,
                'two_factor_enrolment_expires_at' => null,
            ]) === 1;
    }

    /**
     * Where a printed link would send someone, when that is nowhere useful:
     * links are built from APP_URL, and the example value is localhost.
     */
    public static function unreachableHostWarning(): ?string
    {
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        return in_array($host, ['', 'localhost', '127.0.0.1'], true)
            ? 'APP_URL is '.config('app.url').', so this link points at this machine. Set APP_URL to the address admins use.'
            : null;
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function holderOfHash(string $hash): ?User
    {
        $user = User::query()
            ->where('two_factor_enrolment_hash', $hash)
            ->where('two_factor_enrolment_expires_at', '>', now())
            ->first();

        return $user !== null && $user->isPlatformAdmin() && ! $user->hasTwoFactor() ? $user : null;
    }
}
