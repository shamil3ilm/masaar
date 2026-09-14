<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Domains\Compliance\Fatoora\Enums\RequeueOutcome;
use App\Domains\Compliance\Fatoora\Models\OfflineItem;
use Illuminate\Support\Facades\DB;

/**
 * Puts a failed offline submission back in the queue.
 *
 * An operator action from the platform console, which spans tenants, so the
 * item is read past the tenant scope. The row is locked while its state is
 * checked and changed, so two retries cannot both reset it and a worker cannot
 * change it between the check and the reset.
 */
class Requeuer
{
    public function requeue(string $queueId): RequeueOutcome
    {
        return DB::transaction(function () use ($queueId): RequeueOutcome {
            $item = OfflineItem::withoutTenantScope(
                fn () => OfflineItem::query()->lockForUpdate()->find($queueId)
            );

            if ($item === null) {
                return RequeueOutcome::NotFound;
            }

            if (! $item->isFailed()) {
                return RequeueOutcome::NotFailed;
            }

            $item->requeue();

            return RequeueOutcome::Requeued;
        });
    }
}
