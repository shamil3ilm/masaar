<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Domains\Compliance\Fatoora\Models\OfflineItem;

/**
 * How much of the current tenant's offline queue is waiting, and for how long.
 *
 * OfflineItem uses BelongsToTenant, so the count is the resolved tenant's.
 */
class QueueBacklog
{
    /**
     * A pending item queued longer ago than this is counted as stuck.
     */
    private const STUCK_AFTER_MINUTES = 30;

    /**
     * Pending items, and how many of them are stuck.
     *
     * One query with a conditional aggregate.
     *
     * @return array{pending: int, stuck: int}
     */
    public function measure(): array
    {
        $stats = OfflineItem::query()
            ->where('state', OfflineItem::PENDING)
            ->selectRaw('
                COUNT(*) as pending,
                SUM(CASE WHEN queued_at < ? THEN 1 ELSE 0 END) as stuck
            ', [now()->subMinutes(self::STUCK_AFTER_MINUTES)])
            ->first();

        return [
            'pending' => (int) ($stats->pending ?? 0),
            'stuck' => (int) ($stats->stuck ?? 0),
        ];
    }
}
