<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use App\Domains\Webhook\Models\Webhook;
use App\Domains\Webhook\Models\WebhookLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A delivery that failed can be sent again.
 *
 * Needs no new machinery: the log row holds the event and the payload, and
 * WebhookService::deliver() is public, so a replay is the stored attempt handed
 * back to the same method. What it does need is care, because unlike every
 * other screen in the console this one makes a real HTTP request to a
 * customer's live server the moment it is pressed:
 *
 *   - it is a POST behind a confirm, never a link something can prefetch;
 *   - the log id is scoped to the endpoint in the URL, so one customer's
 *     invoice data cannot be posted to another customer's server;
 *   - an unreachable endpoint is a sentence, not a stack trace;
 *   - the replay records itself, because a resend is a delivery and the next
 *     person reading the log needs to see that it happened.
 */
class WebhookReplayTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Any value, as long as the session and the request agree on it.
     */
    private const TOKEN = 'replay-csrf-token';

    /**
     * The receiver gets the event again, the attempt is logged as its own row,
     * and the admin is told the receiver may now process it twice.
     *
     * The payload assertion is the point of the unwrapping in
     * WebhookLog::sentPayload(): the stored column is the whole envelope that
     * was posted, so a replay that passed it straight back would bury the
     * invoice under data.data, where no customer's handler looks.
     */
    public function test_a_delivery_can_be_replayed(): void
    {
        Http::fake(['203.0.113.10/*' => Http::response('{"received":true}', 200)]);

        $webhook = $this->endpoint($this->organization('Acme Trading'), 'https://203.0.113.10/hooks/masaar');
        $delivery = $this->delivery($webhook, ['response_status' => 503, 'success' => false]);

        $response = $this->replay($webhook, $delivery);

        $response->assertRedirect('/admin/webhooks/'.$webhook->id);
        $response->assertSessionHas(
            'success',
            fn (string $message): bool => str_contains($message, 'may process this event a second time')
        );

        $attempts = WebhookLog::query()->where('webhook_id', $webhook->id)->get();
        $replayed = $attempts->first(fn (WebhookLog $log): bool => $log->id !== $delivery->id);

        $this->assertCount(2, $attempts);
        $this->assertSame('invoice.cleared', $replayed?->event);
        $this->assertSame(200, $replayed?->response_status);
        $this->assertTrue($replayed?->success);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://203.0.113.10/hooks/masaar'
                && $request['event'] === 'invoice.cleared'
                && $request['data'] === ['invoice_number' => 'INV-9001'];
        });
    }

    /**
     * The common case is an endpoint that is still down, so the failure path is
     * the one an admin meets first. A 500 page would tell them nothing and look
     * like the console broke rather than the customer's server.
     *
     * A connection failure rather than a refusing status, because that is what
     * "the endpoint is down" actually looks like: the client throws and nothing
     * answers. WebhookService catches it and records the attempt with status 0,
     * so what reaches the controller is a false return - and a status of 0 on
     * the new row is the proof that no response arrived at all.
     */
    public function test_a_failed_replay_is_reported(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $webhook = $this->endpoint($this->organization('Acme Trading'), 'https://203.0.113.10/hooks/masaar');
        $delivery = $this->delivery($webhook, ['response_status' => 500, 'success' => false]);

        $response = $this->replay($webhook, $delivery);

        $response->assertRedirect('/admin/webhooks/'.$webhook->id);
        $response->assertSessionHas(
            'error',
            fn (string $message): bool => str_contains($message, 'did not accept it')
        );
        $response->assertSessionMissing('success');

        $attempts = WebhookLog::query()->where('webhook_id', $webhook->id)->get();
        $replayed = $attempts->first(fn (WebhookLog $log): bool => $log->id !== $delivery->id);

        $this->assertCount(2, $attempts);
        $this->assertSame(0, $replayed?->response_status);
        $this->assertFalse($replayed?->success);
        $this->assertStringContainsString('Operation timed out', (string) $replayed?->response_body);
    }

    /**
     * The endpoint in the URL is what authorizes the log id, not decoration.
     * Without the pairing, one customer's invoice payload could be posted to
     * another customer's server by editing two ids in an address bar.
     */
    public function test_a_foreign_log_is_refused(): void
    {
        Http::fake();

        $acme = $this->endpoint($this->organization('Acme Trading'), 'https://203.0.113.10/hooks/masaar');
        $rawafid = $this->endpoint($this->organization('Rawafid Logistics'), 'https://rawafid.test/webhooks/zatca');
        $delivery = $this->delivery($acme, ['response_status' => 503, 'success' => false]);

        $this->replay($rawafid, $delivery)->assertNotFound();

        Http::assertNothingSent();
        $this->assertSame(1, WebhookLog::query()->count());
    }

    /**
     * Press Replay, as a platform admin, inside a request.
     *
     * asRequest() because the endpoint lookup has to cross tenants: the admin
     * belongs to no organization, and in console context - which PHPUnit is -
     * TenantScope stands down and the lookup would succeed whether or not it
     * asked to.
     *
     * The token because that same switch turns CSRF protection back on:
     * ValidateCsrfToken::handle() returns early only while the app reports it
     * is running unit tests, which it stops doing in here. A POST without a
     * token is a 419, so this is the one place in the suite where the console's
     * CSRF protection is actually exercised rather than asserted as posture.
     */
    /**
     * A disabled endpoint receives nothing, including a replay.
     *
     * is_active is the one gate that says "do not deliver here", and
     * WebhookService::deliveriesFor() honours it on every ordinary dispatch.
     * Replay called deliver() directly and so went around it - the only path
     * in the system that could post to an endpoint a customer had switched
     * off.
     *
     * Worse for the case that matters: recordFailure() sets is_active to
     * false after ten consecutive failures, so the endpoints a replay would
     * most plausibly be aimed at are exactly the ones the system had already
     * given up on. Each replay would have added another failure to the count.
     */
    public function test_a_disabled_endpoint_is_not_replayed(): void
    {
        Http::fake(['203.0.113.10/*' => Http::response('{"received":true}', 200)]);

        $webhook = $this->endpoint(
            $this->organization('Acme Trading'),
            'https://203.0.113.10/hooks/masaar',
            active: false,
            failures: 10,
        );
        $delivery = $this->delivery($webhook, ['response_status' => 503, 'success' => false]);

        $response = $this->replay($webhook, $delivery);

        $response->assertRedirect('/admin/webhooks/'.$webhook->id);
        $response->assertSessionHas(
            'error',
            fn (string $message): bool => str_contains($message, 'disabled')
        );

        // Nothing left the building, and nothing was written as though it had.
        Http::assertNothingSent();
        $this->assertSame(1, WebhookLog::query()->where('webhook_id', $webhook->id)->count());
        $this->assertSame(10, (int) $webhook->fresh()?->failure_count);
    }

    private function replay(Webhook $webhook, WebhookLog $delivery): TestResponse
    {
        return $this->asRequest(fn (): TestResponse => $this->actingAs($this->admin())
            ->withSession(['_token' => self::TOKEN])
            ->post(
                '/admin/webhooks/'.$webhook->id.'/replay/'.$delivery->id,
                ['_token' => self::TOKEN]
            ));
    }

    private function admin(): User
    {
        return User::factory()->create(['is_platform_admin' => true]);
    }

    private function organization(string $name): Organization
    {
        return Organization::create(['name' => $name, 'country' => 'SA']);
    }

    private function endpoint(Organization $organization, string $url, bool $active = true, int $failures = 0): Webhook
    {
        return Webhook::create([
            'org_id' => $organization->id,
            'url' => $url,
            'secret' => str_repeat('s', 64),
            'events' => ['invoice.cleared', 'invoice.rejected'],
            'is_active' => $active,
            'failure_count' => $failures,
        ]);
    }

    /**
     * A logged attempt, with the payload shaped the way deliver() writes it:
     * the whole posted envelope, with the event data nested under `data`.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function delivery(Webhook $webhook, array $attributes): WebhookLog
    {
        return WebhookLog::create([
            'webhook_id' => $webhook->id,
            'event' => 'invoice.cleared',
            'payload' => [
                'id' => '6f1a4b2c-0000-4000-8000-000000000001',
                'event' => 'invoice.cleared',
                'timestamp' => '2026-10-01T09:00:00.000000Z',
                'data' => ['invoice_number' => 'INV-9001'],
            ],
            'response_body' => 'upstream connect error',
            'duration_ms' => 1842,
            ...$attributes,
        ]);
    }
}
