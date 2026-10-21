<?php

declare(strict_types=1);

namespace App\Domains\Platform\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;

/**
 * When the scheduler last ran, and when each queue's worker last picked up
 * a job: the two things a deployment can stop doing without a single error
 * in any log. A dead worker leaves simplified invoices unreported and a
 * stopped scheduler leaves the offline queue undrained, and both look like
 * a quiet day.
 *
 * platform:heartbeat records the scheduler's beat every minute and sends a
 * QueueHeartbeat down each queue, whose worker records its own. Kept in the
 * shared cache, so every replica's console reads the same beats.
 */
class Heartbeat
{
    public const SCHEDULER = 'scheduler';

    private const PREFIX = 'platform:heartbeat:';

    public function __construct(
        private readonly Repository $cache,
    ) {}

    /** What a queue's worker beats under. */
    public static function queue(string $name): string
    {
        return 'queue:'.$name;
    }

    public function beat(string $what): void
    {
        $this->cache->forever(self::PREFIX.$what, now()->getTimestamp());
    }

    /** When it last beat; null when it never has, or the cache was cleared since. */
    public function last(string $what): ?CarbonImmutable
    {
        $at = $this->cache->get(self::PREFIX.$what);

        return is_int($at) ? CarbonImmutable::createFromTimestamp($at) : null;
    }
}
