<?php

declare(strict_types=1);

namespace Tests\Feature\Webhook;

use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use App\Domains\Webhook\Models\Webhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * An integration manages its webhooks with its own licence key.
 *
 * /v1 could create and remove a webhook but not list its events, send a
 * test delivery, rotate its secret or read its delivery log: those needed a
 * signed-in person's token, which an ERP or till does not have.
 */
class PartnerWebhookApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private Webhook $webhook;

    /** @var array<string, string> */
    private array $credential;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        $this->webhook = $this->webhookFor($this->acme);

        License::create([
            'org_id' => $this->acme->id,
            'api_key' => 'cp_test_acme',
            'api_secret_hash' => License::hashSecret('secret-acme'),
            'organization_name' => 'Acme',
            'contact_email' => 'ops@example.test',
            'environment' => 'sandbox',
            'tier' => 'professional',
            'status' => 'active',
            'scopes' => ['webhook.manage'],
        ]);

        $this->credential = ['X-API-Key' => 'cp_test_acme', 'X-API-Secret' => 'secret-acme'];
    }

    public function test_events_are_listed(): void
    {
        $this->getJson('/api/v1/webhooks/events', $this->credential)
            ->assertOk()
            ->assertJsonFragment(['invoice.cleared']);
    }

    public function test_a_test_delivery_is_sent(): void
    {
        $this->postJson("/api/v1/webhooks/{$this->webhook->id}/test", [], $this->credential)->assertOk();

        Http::assertSent(fn ($request) => $request->url() === 'https://203.0.113.20/hooks'
            && ($request->data()['event'] ?? null) === 'webhook.test');
    }

    public function test_the_secret_is_rotated(): void
    {
        $secret = $this->postJson("/api/v1/webhooks/{$this->webhook->id}/rotate-secret", [], $this->credential)
            ->assertOk()
            ->json('data.secret');

        $this->assertNotSame('shhh', $secret);
        $this->assertSame($secret, $this->webhook->fresh()->secret);
    }

    public function test_the_delivery_log_is_read(): void
    {
        $this->postJson("/api/v1/webhooks/{$this->webhook->id}/test", [], $this->credential)->assertOk();

        $this->getJson("/api/v1/webhooks/{$this->webhook->id}/logs", $this->credential)
            ->assertOk()
            ->assertJsonPath('data.0.event', 'webhook.test');
    }

    public function test_another_organizations_webhook_is_hidden(): void
    {
        $theirs = $this->webhookFor(Organization::create(['name' => 'Rival', 'country' => 'SA']));

        $this->getJson("/api/v1/webhooks/{$theirs->id}/logs", $this->credential)->assertNotFound();
        $this->postJson("/api/v1/webhooks/{$theirs->id}/test", [], $this->credential)->assertNotFound();
        $this->postJson("/api/v1/webhooks/{$theirs->id}/rotate-secret", [], $this->credential)->assertNotFound();

        $this->assertSame('shhh', $theirs->fresh()->secret);
        Http::assertNothingSent();
    }

    private function webhookFor(Organization $organization): Webhook
    {
        return Webhook::withoutTenantScope(fn () => Webhook::create([
            'org_id' => $organization->id,
            'url' => 'https://203.0.113.20/hooks',
            'secret' => 'shhh',
            'events' => ['invoice.cleared'],
            'is_active' => true,
        ]));
    }
}
