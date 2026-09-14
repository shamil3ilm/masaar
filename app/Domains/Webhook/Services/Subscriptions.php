<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Services;

use App\Domains\Webhook\Models\Webhook;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * An organization's webhook subscriptions: which endpoints receive which
 * events, and with what secret.
 *
 * Lookups name the organization explicitly as well as passing through
 * BelongsToTenant's scope, so another tenant's subscription is not found. A
 * null organization matches none.
 */
class Subscriptions
{
    /**
     * @return Collection<int, Webhook> newest first
     */
    public function list(?string $organizationId): Collection
    {
        return Webhook::where('org_id', $organizationId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * @throws ModelNotFoundException
     */
    public function find(?string $organizationId, string $id): Webhook
    {
        return Webhook::where('org_id', $organizationId)->findOrFail($id);
    }

    /**
     * Subscribe an endpoint, active and with a fresh secret.
     *
     * @param  list<string>  $events
     */
    public function create(string $organizationId, string $url, array $events): Webhook
    {
        return Webhook::create([
            'org_id' => $organizationId,
            'url' => $url,
            'secret' => Str::random(64),
            'events' => $events,
            'is_active' => true,
            'failure_count' => 0,
        ]);
    }

    /**
     * Apply changes to a subscription.
     *
     * The row is re-read under lock, so a delivery recording a failure cannot
     * land between reading the subscription and writing the change.
     *
     * @param  array{url?: string, events?: list<string>, is_active?: mixed}  $changes
     */
    public function update(Webhook $webhook, array $changes): Webhook
    {
        return DB::transaction(function () use ($webhook, $changes): Webhook {
            $locked = Webhook::query()
                ->whereKey($webhook->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $locked->revise($changes);

            return $locked;
        });
    }

    /**
     * Replace the signing secret and return the new one.
     */
    public function rotateSecret(Webhook $webhook): string
    {
        return $webhook->rotateSecret();
    }

    public function delete(Webhook $webhook): void
    {
        $webhook->delete();
    }

    /**
     * Delivery attempts to one endpoint, newest first.
     */
    public function deliveries(Webhook $webhook, int $perPage): LengthAwarePaginator
    {
        return $webhook->logs()
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }
}
