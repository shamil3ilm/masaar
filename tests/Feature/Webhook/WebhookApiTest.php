<?php

declare(strict_types=1);

namespace Tests\Feature\Webhook;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use App\Domains\Webhook\Models\Webhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Managing webhook subscriptions over the tenant API.
 *
 * The secret is what a receiver verifies deliveries with, so it is shown on
 * creation and rotation only, and never to another tenant.
 */
class WebhookApiTest extends TestCase
{
    use RefreshDatabase;

    private const LISTED = ['id', 'url', 'events', 'is_active', 'failure_count', 'last_triggered_at', 'created_at'];

    private Organization $acme;

    private Organization $rival;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);
        $this->rival = Organization::create(['name' => 'Rival', 'country' => 'SA']);

        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);

        $this->token = JWTAuth::claims(['org_id' => $this->acme->id, 'role' => 'admin'])->fromUser($user);
    }

    public function test_index_lists_own_webhooks(): void
    {
        $older = $this->webhook($this->acme, ['created_at' => '2026-01-01 10:00:00']);
        $newer = $this->webhook($this->acme, ['created_at' => '2026-01-02 10:00:00']);
        $this->webhook($this->rival);

        $response = $this->api()->getJson('/api/webhooks')
            ->assertOk()
            ->assertJsonCount(2, 'data.webhooks')
            ->assertJsonPath('data.webhooks.0.id', $newer->id)
            ->assertJsonPath('data.webhooks.1.id', $older->id);

        $this->assertSame(self::LISTED, array_keys($response->json('data.webhooks.0')));
    }

    public function test_store_returns_secret_once(): void
    {
        $response = $this->api()->postJson('/api/webhooks', [
            'url' => 'https://erp.test/hooks',
            'events' => ['invoice.cleared'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.webhook.url', 'https://erp.test/hooks')
            ->assertJsonPath('data.webhook.events', ['invoice.cleared'])
            ->assertJsonPath('data.webhook.is_active', true)
            ->assertJsonPath('data.message', 'Webhook created. Save the secret - it will not be shown again.');

        $this->assertSame(['id', 'url', 'secret', 'events', 'is_active'], array_keys($response->json('data.webhook')));
        $this->assertSame(64, strlen($response->json('data.webhook.secret')));

        $stored = Webhook::withoutTenantScope(fn () => Webhook::find($response->json('data.webhook.id')));

        $this->assertSame($this->acme->id, $stored->org_id);
        $this->assertSame(0, $stored->failure_count);
    }

    public function test_show_hides_the_secret(): void
    {
        $webhook = $this->webhook($this->acme);

        $response = $this->api()->getJson("/api/webhooks/{$webhook->id}")
            ->assertOk()
            ->assertJsonPath('data.webhook.id', $webhook->id);

        $this->assertSame(self::LISTED, array_keys($response->json('data.webhook')));
    }

    /**
     * Turning an endpoint back on starts its failure count again, or the next
     * failed delivery would disable it at once.
     */
    public function test_reactivation_clears_failures(): void
    {
        $webhook = $this->webhook($this->acme, ['is_active' => false, 'failure_count' => 10]);

        $this->api()->putJson("/api/webhooks/{$webhook->id}", [
            'url' => 'https://erp.test/new',
            'is_active' => true,
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Webhook updated')
            ->assertExactJson([
                'success' => true,
                'message' => 'Webhook updated',
                'data' => ['webhook' => [
                    'id' => $webhook->id,
                    'url' => 'https://erp.test/new',
                    'events' => ['invoice.cleared'],
                    'is_active' => true,
                ]],
            ]);

        $stored = $this->reload($webhook);

        $this->assertTrue($stored->is_active);
        $this->assertSame(0, $stored->failure_count);
        $this->assertSame('https://erp.test/new', $stored->url);
    }

    public function test_other_edits_keep_failures(): void
    {
        $webhook = $this->webhook($this->acme, ['failure_count' => 3]);

        $this->api()->putJson("/api/webhooks/{$webhook->id}", ['events' => ['*']])
            ->assertOk()
            ->assertJsonPath('data.webhook.events', ['*']);

        $stored = $this->reload($webhook);

        $this->assertSame(3, $stored->failure_count);
        $this->assertSame(['*'], $stored->events);
    }

    public function test_rotate_secret_replaces_it(): void
    {
        $webhook = $this->webhook($this->acme);

        $response = $this->api()->postJson("/api/webhooks/{$webhook->id}/rotate-secret")
            ->assertOk()
            ->assertJsonPath('data.message', 'Secret rotated. Save the new secret - it will not be shown again.');

        $secret = $response->json('data.secret');

        $this->assertSame(64, strlen($secret));
        $this->assertNotSame('old-secret', $secret);
        $this->assertSame($secret, $this->reload($webhook)->secret);
    }

    public function test_destroy_removes_webhook(): void
    {
        $webhook = $this->webhook($this->acme);

        $this->api()->deleteJson("/api/webhooks/{$webhook->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Webhook deleted');

        $this->assertNull($this->reload($webhook));
    }

    public function test_rival_webhook_cannot_be_changed(): void
    {
        $theirs = $this->webhook($this->rival);

        $this->api()->putJson("/api/webhooks/{$theirs->id}", ['url' => 'https://evil.test/'])->assertNotFound();
        $this->api()->postJson("/api/webhooks/{$theirs->id}/rotate-secret")->assertNotFound();
        $this->api()->deleteJson("/api/webhooks/{$theirs->id}")->assertNotFound();

        $stored = $this->reload($theirs);

        $this->assertSame('https://erp.test/hooks', $stored->url);
        $this->assertSame('old-secret', $stored->secret);
    }

    private function api(): self
    {
        return $this->withToken($this->token);
    }

    private function webhook(Organization $organization, array $attributes = []): Webhook
    {
        $webhook = (new Webhook)->forceFill([
            'org_id' => $organization->id,
            'url' => 'https://erp.test/hooks',
            'secret' => 'old-secret',
            'events' => ['invoice.cleared'],
            'is_active' => true,
            'failure_count' => 0,
            ...$attributes,
        ]);

        $webhook->save();

        return $webhook;
    }

    private function reload(Webhook $webhook): ?Webhook
    {
        return Webhook::withoutTenantScope(fn () => Webhook::find($webhook->id));
    }
}
