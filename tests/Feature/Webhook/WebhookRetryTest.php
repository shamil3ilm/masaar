<?php

declare(strict_types=1);

namespace Tests\Feature\Webhook;

use App\Domains\Organization\Models\Organization;
use App\Domains\Webhook\Events\CertificateExpiring;
use App\Domains\Webhook\Jobs\DeliverWebhook;
use App\Domains\Webhook\Models\Webhook;
use App\Domains\Webhook\Models\WebhookLog;
use App\Domains\Webhook\Services\WebhookService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A delivery is retried until the receiver answers, and only then counted.
 *
 * Deliveries ran in a loop inside the event listener. One that failed was
 * logged and dropped, so a receiver down for a minute missed the event for
 * good - and when the listener was retried, every endpoint got the event
 * again under a new id, which no receiver can recognise as a repeat.
 */
class WebhookRetryTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Acme', 'country' => 'SA']);
    }

    public function test_each_endpoint_gets_its_own_job(): void
    {
        Queue::fake();
        $first = $this->webhook();
        $second = $this->webhook();

        app(WebhookService::class)->dispatch($this->organization->id, 'invoice.cleared', ['id' => 'x']);

        Queue::assertPushed(DeliverWebhook::class, 2);
        Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job) => $job->webhookId === $first->id);
        Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job) => $job->webhookId === $second->id);
    }

    /**
     * Unanswered is retried, and not yet held against the endpoint.
     */
    public function test_an_unanswered_delivery_is_retried(): void
    {
        Http::fake(['*' => Http::response('busy', 503)]);
        $webhook = $this->webhook();

        $job = $this->job($webhook)->withFakeQueueInteractions();
        $job->handle(app(WebhookService::class));

        $job->assertReleased(30);
        $this->assertSame(0, $this->reload($webhook)->failure_count);
        $this->assertSame(1, WebhookLog::where('webhook_id', $webhook->id)->count());
    }

    /**
     * A refusal is the receiver's answer; sending it again changes nothing.
     */
    public function test_a_refused_delivery_is_not_retried(): void
    {
        Http::fake(['*' => Http::response('bad', 400)]);
        $webhook = $this->webhook();

        $job = $this->job($webhook)->withFakeQueueInteractions();
        $job->handle(app(WebhookService::class));

        $job->assertNotReleased();
        $this->assertSame(1, $this->reload($webhook)->failure_count);
    }

    public function test_the_last_try_counts_as_failure(): void
    {
        Http::fake(['*' => Http::response('busy', 503)]);
        $webhook = $this->webhook();

        $job = $this->job($webhook)->withFakeQueueInteractions();
        $job->tries = 1;
        $job->handle(app(WebhookService::class));

        $job->assertNotReleased();
        $this->assertSame(1, $this->reload($webhook)->failure_count);
    }

    /**
     * Every attempt carries the same delivery id, so a receiver can discard
     * the second copy of a delivery it processed but failed to acknowledge.
     */
    public function test_retries_carry_one_delivery_id(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('busy', 503)]);
        $this->webhook();

        app(WebhookService::class)->dispatch($this->organization->id, 'invoice.cleared', ['id' => 'x']);

        $job = Queue::pushed(DeliverWebhook::class)->sole();
        $job->withFakeQueueInteractions()->handle(app(WebhookService::class));
        $job->withFakeQueueInteractions()->handle(app(WebhookService::class));

        $ids = Http::recorded()->map(fn (array $pair) => $pair[0]->header('X-Webhook-ID')[0]);

        $this->assertCount(2, $ids);
        $this->assertSame([$job->deliveryId], $ids->unique()->values()->all());
    }

    /**
     * A name that does not resolve is a resolver blip as often as a dead
     * domain, so it is retried rather than dropped.
     */
    public function test_an_unresolved_host_is_retried(): void
    {
        Http::fake();
        $webhook = $this->webhook(url: 'https://nx.invalid/hooks');

        $job = $this->job($webhook)->withFakeQueueInteractions();
        $job->handle(app(WebhookService::class));

        $job->assertReleased(30);
        Http::assertNothingSent();
        $this->assertSame(0, $this->reload($webhook)->failure_count);
    }

    /**
     * An event inside a transaction that rolls back never happened.
     */
    public function test_rolled_back_events_are_not_sent(): void
    {
        // The real (sync) queue: the fake pushes at once, ignoring afterCommit.
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $this->webhook();

        try {
            DB::transaction(function (): void {
                app(WebhookService::class)->dispatch($this->organization->id, 'invoice.cleared', ['id' => 'x']);

                throw new DomainException('rolled back');
            });
        } catch (DomainException) {
        }

        Http::assertNothingSent();
    }

    /**
     * The partner guide offered certificate.expiring; nothing sent it.
     */
    public function test_certificate_expiry_reaches_subscribed_endpoints(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $this->webhook(['certificate.expiring']);

        event(new CertificateExpiring($this->organization->id, 5, '2026-10-20T00:00:00+03:00'));

        Http::assertSent(fn (Request $request) => $request['event'] === 'certificate.expiring'
            && $request['data']['days_remaining'] === 5
            && $request['data']['severity'] === 'high');
    }

    public function test_old_delivery_logs_are_pruned(): void
    {
        $webhook = $this->webhook();
        $old = $this->log($webhook, now()->subDays(WebhookLog::RETENTION_DAYS + 1));
        $recent = $this->log($webhook, now()->subDays(WebhookLog::RETENTION_DAYS - 1));

        $this->artisan('model:prune', ['--model' => [WebhookLog::class]])->assertSuccessful();

        $this->assertNull(WebhookLog::find($old->id));
        $this->assertNotNull(WebhookLog::find($recent->id));
    }

    /**
     * @param  list<string>  $events
     */
    private function webhook(array $events = ['invoice.cleared'], string $url = 'https://203.0.113.20/hooks'): Webhook
    {
        $webhook = (new Webhook)->forceFill([
            'org_id' => $this->organization->id,
            'url' => $url,
            'secret' => 'shhh',
            'events' => $events,
            'is_active' => true,
            'failure_count' => 0,
        ]);

        $webhook->save();

        return $webhook;
    }

    private function job(Webhook $webhook): DeliverWebhook
    {
        return new DeliverWebhook($webhook->id, 'invoice.cleared', ['id' => 'x'], 'delivery-1');
    }

    private function log(Webhook $webhook, \DateTimeInterface $at): WebhookLog
    {
        $log = WebhookLog::create([
            'webhook_id' => $webhook->id,
            'event' => 'invoice.cleared',
            'payload' => [],
            'response_status' => 200,
            'response_body' => '',
            'duration_ms' => 1,
            'success' => true,
        ]);

        $log->forceFill(['created_at' => $at])->save();

        return $log;
    }

    private function reload(Webhook $webhook): Webhook
    {
        return Webhook::withoutTenantScope(fn () => Webhook::findOrFail($webhook->id));
    }
}
