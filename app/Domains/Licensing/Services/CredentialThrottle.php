<?php

declare(strict_types=1);

namespace App\Domains\Licensing\Services;

use Illuminate\Support\Facades\RateLimiter;

/**
 * How many wrong API credentials an address may send.
 *
 * Nothing counted them: the licence check runs before the request budget,
 * so a caller could guess secrets for a key, or probe for keys, without
 * limit. Two counters, as sign-in keeps (SignInThrottle). Per key and
 * address, tight, so one integration's typos lock only itself and only from
 * where they are made. And per address, across keys, so probing for keys
 * that exist runs out quickly. A key is counted by its hash: the counter
 * name is stored in the cache.
 */
final class CredentialThrottle
{
    public const PER_KEY_AND_ADDRESS = 10;

    public const PER_ADDRESS = 30;

    public const DECAY_SECONDS = 300;

    /**
     * Seconds until credentials may be tried again, or zero if they may now.
     *
     * @return array{0: int, 1: int} seconds, and the limit that was reached
     */
    public function lockedFor(string $key, ?string $address): array
    {
        foreach ($this->counters($key, $address) as $counter => $limit) {
            if (RateLimiter::tooManyAttempts($counter, $limit)) {
                return [max(1, RateLimiter::availableIn($counter)), $limit];
            }
        }

        return [0, 0];
    }

    public function failed(string $key, ?string $address): void
    {
        foreach (array_keys($this->counters($key, $address)) as $counter) {
            RateLimiter::hit($counter, self::DECAY_SECONDS);
        }
    }

    /**
     * The right secret forgives that key's wrong ones from this address. The
     * address's own count stays: holding one key says nothing about the
     * others it tried.
     */
    public function succeeded(string $key, ?string $address): void
    {
        RateLimiter::clear($this->keyCounter($key, $address));
    }

    /**
     * @return array<string, int> counter => attempts allowed
     */
    private function counters(string $key, ?string $address): array
    {
        return [
            $this->keyCounter($key, $address) => self::PER_KEY_AND_ADDRESS,
            'api-credentials-address:'.$address => self::PER_ADDRESS,
        ];
    }

    private function keyCounter(string $key, ?string $address): string
    {
        return 'api-credentials:'.hash('sha256', $key).'|'.$address;
    }
}
