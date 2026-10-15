<?php

declare(strict_types=1);

namespace App\Domains\Auth\Services;

use App\Domains\Auth\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * How many wrong passwords and codes a sign-in may take, on every surface.
 *
 * The console counted failures per email and address, so an attacker with a
 * pool of addresses had five guesses from each, without end. The API counted
 * nothing beyond the anonymous request budget, and checked an admin's second
 * factor with no limit at all: a million six-digit codes, one request each.
 *
 * Two counters for a password. Per email and address, tight, as before. And
 * per account, from anywhere, looser - loose enough that a stranger cannot
 * lock a real user out for long with a few requests, tight enough that a
 * distributed guess gets about two thousand tries a day instead of unlimited.
 *
 * One counter for a second-factor code, per user, shared by the console and
 * the API so a code cannot be guessed on one while the other is locked.
 */
final class SignInThrottle
{
    public const PER_ADDRESS = 5;

    public const ADDRESS_DECAY_SECONDS = 300;

    public const PER_ACCOUNT = 20;

    public const ACCOUNT_DECAY_SECONDS = 900;

    public const CODE_ATTEMPTS = 5;

    public const CODE_DECAY_SECONDS = 300;

    /**
     * Seconds until a password may be tried again, or zero if it may now.
     */
    public function passwordLockedFor(string $email, ?string $ip): int
    {
        foreach ($this->passwordKeys($email, $ip) as $key => $limit) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                return max(1, RateLimiter::availableIn($key));
            }
        }

        return 0;
    }

    public function passwordFailed(string $email, ?string $ip): void
    {
        RateLimiter::hit($this->addressKey($email, $ip), self::ADDRESS_DECAY_SECONDS);
        RateLimiter::hit($this->accountKey($email), self::ACCOUNT_DECAY_SECONDS);
    }

    /**
     * The right password forgives the wrong ones before it, from anywhere:
     * whoever made them did not hold it.
     */
    public function passwordSucceeded(string $email, ?string $ip): void
    {
        RateLimiter::clear($this->addressKey($email, $ip));
        RateLimiter::clear($this->accountKey($email));
    }

    public function codeLockedFor(User $user): int
    {
        return RateLimiter::tooManyAttempts($this->codeKey($user), self::CODE_ATTEMPTS)
            ? max(1, RateLimiter::availableIn($this->codeKey($user)))
            : 0;
    }

    public function codeFailed(User $user): void
    {
        RateLimiter::hit($this->codeKey($user), self::CODE_DECAY_SECONDS);
    }

    public function codeSucceeded(User $user): void
    {
        RateLimiter::clear($this->codeKey($user));
    }

    /**
     * @return array<string, int> key => attempts allowed
     */
    private function passwordKeys(string $email, ?string $ip): array
    {
        return [
            $this->addressKey($email, $ip) => self::PER_ADDRESS,
            $this->accountKey($email) => self::PER_ACCOUNT,
        ];
    }

    private function addressKey(string $email, ?string $ip): string
    {
        return 'sign-in:'.Str::lower($email).'|'.$ip;
    }

    private function accountKey(string $email): string
    {
        return 'sign-in-account:'.Str::lower($email);
    }

    private function codeKey(User $user): string
    {
        return 'mfa:'.$user->id;
    }
}
