<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Services;

use App\Domains\Webhook\Models\Webhook;
use App\Domains\Webhook\Models\WebhookLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * Customer endpoints and what happened on the way to them, for the platform
 * team.
 *
 * WebhookService has written a webhook_logs row for every attempt since the
 * beginning - the status the receiver answered, the body it returned, how long
 * it took - and nothing read them back. "Our webhook stopped working" was
 * answerable only from a psql prompt, so in practice it was not answered. This
 * is the same rows behind a URL.
 *
 * Reads only. Replaying a delivery is WebhookService::deliver(), which this
 * deliberately does not call: a report that sends live HTTP to a customer on
 * being rendered is a report nobody can safely refresh.
 */
class WebhookReport
{
    /**
     * Attempts shown for one endpoint.
     *
     * A busy endpoint has tens of thousands of rows and nobody reads past the
     * recent ones; the whole table on one page is a timeout, not a feature.
     */
    private const DELIVERIES = 50;

    /**
     * Every endpoint on the platform, newest first.
     *
     * Cross-tenant on purpose. The console is the one place that reads across
     * taxpayers, and whoever is asking whether events are arriving does not
     * know which tenant to ask as. Inside a request TenantScope would scope
     * these to the viewing admin's organization - a platform admin has none,
     * and a null tenant matches no rows - so without stepping past it this
     * page would be permanently, silently empty.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function endpoints(): Collection
    {
        return Webhook::withoutTenantScope(fn (): Collection => Webhook::query()
            // Resolved here, inside the suspended scope, rather than lazily
            // from the view.
            ->with('org')
            ->withCount([
                'logs as deliveries_count',
                'logs as failures_count' => fn (Builder $query) => $query->where('success', false),
            ])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Webhook $webhook): array => [
                'id' => $webhook->id,
                'url' => $webhook->url,
                'organization' => $webhook->org?->name,
                'events' => $webhook->events ?? [],
                'is_active' => (bool) $webhook->is_active,
                // Ten consecutive failures disable the endpoint, so this
                // number is how close the customer is to being switched off.
                'failure_count' => (int) $webhook->failure_count,
                'last_triggered_at' => $webhook->last_triggered_at,
                'deliveries' => (int) $webhook->deliveries_count,
                'failures' => (int) $webhook->failures_count,
            ]));
    }

    /**
     * One endpoint, whichever tenant owns it.
     *
     * Unfindable is a 404 rather than an empty page: an id that names nothing
     * is a mistyped URL, not an endpoint with no deliveries.
     *
     * @throws ModelNotFoundException
     */
    public function endpoint(string $webhookId): Webhook
    {
        return Webhook::withoutTenantScope(
            fn (): Webhook => Webhook::query()->with('org')->findOrFail($webhookId)
        );
    }

    /**
     * How many attempts this endpoint has had in total.
     *
     * deliveries() returns the newest fifty, and the screen said "50 most
     * recent attempts" whether the endpoint had fifty or five thousand. This
     * is the screen somebody opens to answer "our webhook stopped working",
     * and a truncated log that reads as a complete one is how a reader
     * concludes an endpoint was only ever tried fifty times.
     */
    public function deliveryCount(string $webhookId): int
    {
        return WebhookLog::query()->where('webhook_id', $webhookId)->count();
    }

    /**
     * The number of attempts one page of this report will show.
     */
    public function deliveryCap(): int
    {
        return self::DELIVERIES;
    }

    /**
     * The recent delivery attempts for one endpoint, newest first.
     *
     * webhook_logs carries no org_id and no tenant scope - it is reached
     * through its endpoint, which is what the webhook_id filter is.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function deliveries(string $webhookId): Collection
    {
        return WebhookLog::query()
            ->where('webhook_id', $webhookId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::DELIVERIES)
            ->get()
            ->map(fn (WebhookLog $log): array => [
                'id' => $log->id,
                'event' => $log->event,
                // 0 is what the service records when the request never got an
                // answer at all - DNS, refused connection, timeout - which is
                // a different failure from a 500 and reads as one.
                'response_status' => (int) $log->response_status,
                'response_body' => $log->response_body,
                'duration_ms' => (int) $log->duration_ms,
                'success' => (bool) $log->success,
                'attempted_at' => $log->created_at,
            ]);
    }

    /**
     * One delivery attempt, reached through the endpoint that made it.
     *
     * The webhook_id condition is the authorization, not an optimisation.
     * Replaying posts the stored payload to whichever endpoint the URL names,
     * so a log id that is accepted against any endpoint is a way to send one
     * customer's invoice data to another customer's server by editing two ids
     * in an address bar. A log that does not belong to this endpoint is
     * unfindable, which is a 404.
     *
     * @throws ModelNotFoundException
     */
    public function delivery(string $webhookId, string $logId): WebhookLog
    {
        return WebhookLog::query()
            ->where('webhook_id', $webhookId)
            ->findOrFail($logId);
    }
}
