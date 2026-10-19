<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Networks\Peppol\Simulator;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * The simulated provider's own books: the messages it took, under which
 * idempotency key, and how often each key was tried.
 *
 * Kept in the cache, not in a table of this platform's: it is the
 * provider's record, not the taxpayer's, and a real provider keeps it on
 * its side. Clearing the cache is the provider forgetting - a status lookup
 * then has no answer, as it would for a message the provider never had.
 */
final class SimulatorLedger
{
    private const PREFIX = 'peppol:simulator:';

    /** Longer than any demo, and far longer than the status poll keeps asking. */
    private const TTL_SECONDS = 30 * 24 * 3600;

    /**
     * Run $work alone for one idempotency key, so two submissions of one
     * document cannot both be taken.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function exclusively(string $key, Closure $work): mixed
    {
        return Cache::lock(self::PREFIX.'lock:'.$key, 60)->block(30, $work);
    }

    public function messageIdFor(string $key): ?string
    {
        $id = Cache::get(self::PREFIX.'key:'.$key);

        return is_string($id) ? $id : null;
    }

    /** This attempt's number for the key, counting from 1. */
    public function attempt(string $key): int
    {
        $name = self::PREFIX.'attempts:'.$key;
        Cache::add($name, 0, self::TTL_SECONDS);

        return (int) Cache::increment($name);
    }

    public function find(string $messageId): ?SimulatedMessage
    {
        $data = Cache::get(self::PREFIX.'message:'.$messageId);

        return is_array($data) ? SimulatedMessage::fromArray($data) : null;
    }

    public function save(SimulatedMessage $message): void
    {
        Cache::put(self::PREFIX.'message:'.$message->messageId, $message->toArray(), self::TTL_SECONDS);
        Cache::put(self::PREFIX.'key:'.$message->idempotencyKey, $message->messageId, self::TTL_SECONDS);
    }
}
