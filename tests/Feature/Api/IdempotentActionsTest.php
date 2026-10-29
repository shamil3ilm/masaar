<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domains\Invoice\Models\InvoiceSchedule;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use App\Domains\Platform\Models\IdempotentRequest;
use App\Domains\Webhook\Models\Webhook;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\MakesSchedules;
use Tests\TestCase;

/**
 * An Idempotency-Key on the routes that make or act and keep no key of
 * their own: a schedule, a webhook, a new webhook secret. A retry after a
 * timeout answers with what the first request did instead of doing it again.
 */
class IdempotentActionsTest extends TestCase
{
    use MakesSchedules;
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00:00', 'UTC'));
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $this->makeOrganization();

        $issued = License::createWithCredentials(['org_id' => $this->acme->id, 'organization_name' => 'Acme', 'contact_email' => 'erp@example.test', 'tier' => 'enterprise']);
        License::withoutGlobalScopes()->whereKey($issued['license']->id)->update(['status' => 'active', 'scopes' => json_encode(['schedule.manage', 'webhook.manage'])]);
        $this->headers = ['X-API-Key' => $issued['api_key'], 'X-API-Secret' => $issued['api_secret']];
    }

    public function test_a_retried_schedule_is_made_once(): void
    {
        $body = ['name' => 'Retainer', 'frequency' => 'monthly', 'start_date' => '2026-11-01', 'invoice' => $this->scheduledInvoice()];
        $headers = [...$this->headers, 'Idempotency-Key' => 'schedule-1'];

        $first = $this->postJson('/api/v1/schedules', $body, $headers)->assertCreated();
        $again = $this->postJson('/api/v1/schedules', $body, $headers)
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json('data.schedule.id'), $again->json('data.schedule.id'));
        $this->assertSame(1, InvoiceSchedule::acrossTenants()->count());
        $first->assertHeaderMissing('Idempotent-Replayed');
    }

    public function test_another_body_is_refused(): void
    {
        $body = ['name' => 'Retainer', 'frequency' => 'monthly', 'start_date' => '2026-11-01', 'invoice' => $this->scheduledInvoice()];
        $headers = [...$this->headers, 'Idempotency-Key' => 'schedule-2'];

        $this->postJson('/api/v1/schedules', $body, $headers)->assertCreated();
        $this->postJson('/api/v1/schedules', [...$body, 'name' => 'Other'], $headers)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED')
            ->assertJsonPath('error.category', 'idempotency');

        $this->assertSame(1, InvoiceSchedule::acrossTenants()->count());
    }

    public function test_a_refused_request_may_retry(): void
    {
        $headers = [...$this->headers, 'Idempotency-Key' => 'schedule-3'];

        $this->postJson('/api/v1/schedules', ['name' => 'Retainer'], $headers)->assertUnprocessable();
        $this->postJson('/api/v1/schedules', ['name' => 'Retainer', 'frequency' => 'monthly', 'start_date' => '2026-11-01', 'invoice' => $this->scheduledInvoice()], $headers)
            ->assertCreated()
            ->assertHeaderMissing('Idempotent-Replayed');
    }

    public function test_a_webhook_is_made_once(): void
    {
        $body = ['url' => 'https://203.0.113.20/hooks', 'events' => ['invoice.cleared']];
        $headers = [...$this->headers, 'Idempotency-Key' => 'webhook-1'];

        $secret = $this->postJson('/api/v1/webhooks', $body, $headers)->assertCreated()->json('data.webhook.secret');
        $this->postJson('/api/v1/webhooks', $body, $headers)
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.webhook.secret', $secret);

        $this->assertSame(1, Webhook::acrossTenants()->count());
        // The answer holds the secret, so it is not kept in the clear.
        $this->assertStringNotContainsString($secret, (string) IdempotentRequest::query()->toBase()->value('response'));
    }

    public function test_a_secret_is_rotated_once(): void
    {
        $id = $this->postJson('/api/v1/webhooks', ['url' => 'https://203.0.113.20/hooks', 'events' => ['invoice.cleared']], $this->headers)
            ->assertCreated()->json('data.webhook.id');
        $headers = [...$this->headers, 'Idempotency-Key' => 'rotate-1'];

        $secret = $this->postJson("/api/v1/webhooks/{$id}/rotate-secret", [], $headers)->assertOk()->json('data.secret');
        $this->postJson("/api/v1/webhooks/{$id}/rotate-secret", [], $headers)
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.secret', $secret);

        $this->assertSame($secret, Webhook::acrossTenants()->findOrFail($id)->secret);
    }

    public function test_the_key_is_per_organization(): void
    {
        $body = ['url' => 'https://203.0.113.20/hooks', 'events' => ['invoice.cleared']];
        $this->postJson('/api/v1/webhooks', $body, [...$this->headers, 'Idempotency-Key' => 'shared'])->assertCreated();

        $rival = Organization::create(['name' => 'Rival', 'country' => 'SA', 'status' => 'active']);
        $issued = License::createWithCredentials(['org_id' => $rival->id, 'organization_name' => 'Rival', 'contact_email' => 'r@example.test', 'tier' => 'enterprise']);
        License::withoutGlobalScopes()->whereKey($issued['license']->id)->update(['status' => 'active', 'scopes' => json_encode(['webhook.manage'])]);

        $this->postJson('/api/v1/webhooks', $body, ['X-API-Key' => $issued['api_key'], 'X-API-Secret' => $issued['api_secret'], 'Idempotency-Key' => 'shared'])
            ->assertCreated()
            ->assertHeaderMissing('Idempotent-Replayed');
    }
}
