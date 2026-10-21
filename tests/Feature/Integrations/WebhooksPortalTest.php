<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Domains\Auth\Models\User;
use App\Domains\Licensing\Enums\LicenseTier;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use App\Domains\Webhook\Models\Webhook;
use App\Domains\Webhook\Models\WebhookLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Portal › Integrations › Webhooks: an organization's administrators add,
 * change and remove endpoints with events from the catalogue, see the
 * signing secret once (and again only by rotating it), send a test, and
 * read each endpoint's recent deliveries with their answers and retries -
 * and no personal data from what was sent or answered.
 */
class WebhooksPortalTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://203.0.113.20/hooks';

    private Organization $acme;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $this->acme = Organization::create(['name' => 'Acme Trading', 'country' => 'SA', 'vat_number' => '300000000000003', 'status' => 'active']);
        $this->admin = $this->memberAs('admin');
        $this->licence(LicenseTier::Professional);
    }

    public function test_the_signing_secret_is_shown_once(): void
    {
        $response = $this->asAdmin()->post(route('portal.integrations.webhooks.store'), ['url' => self::URL, 'events' => ['invoice.cleared', 'invoice.rejected']]);

        $endpoint = Webhook::withoutTenantScope(fn () => Webhook::query()->sole());
        $response->assertRedirect(route('portal.integrations.webhooks.show', $endpoint->id))->assertSessionHas('webhook_secret', $endpoint->secret);
        $this->assertSame([$this->acme->id, self::URL, ['invoice.cleared', 'invoice.rejected'], true], [$endpoint->org_id, $endpoint->url, $endpoint->events, $endpoint->is_active]);

        $this->show($endpoint)->assertOk()->assertSee($endpoint->secret);
        $this->show($endpoint)->assertOk()->assertDontSee($endpoint->secret);
        $this->asAdmin()->get(route('portal.integrations.webhooks.index'))->assertOk()->assertSee(self::URL)->assertDontSee($endpoint->secret);
    }

    public function test_events_come_from_the_catalogue(): void
    {
        $this->asAdmin()->post(route('portal.integrations.webhooks.store'), ['url' => self::URL, 'events' => ['invoice.paid']])
            ->assertSessionHasErrors('events.0');

        $this->asAdmin()->post(route('portal.integrations.webhooks.store'), ['url' => 'http://203.0.113.20/hooks', 'events' => ['invoice.cleared']])
            ->assertSessionHasErrors('url');

        $this->assertSame(0, Webhook::withoutTenantScope(fn () => Webhook::query()->count()));
    }

    /** One true reason for a plain-http address, not a false "not a URL". */
    public function test_an_http_address_is_explained(): void
    {
        $this->asAdmin()->post(route('portal.integrations.webhooks.store'), ['url' => 'http://127.0.0.1:9999/hook', 'events' => ['invoice.cleared']])
            ->assertSessionHasErrors(['url' => __('Use an https:// address: webhooks are only sent over an encrypted connection.')]);

        $this->assertCount(1, session('errors')->get('url'));
    }

    /** Every event has words, and only the country's own are offered. */
    public function test_the_country_events_are_labelled(): void
    {
        $this->asAdmin()->get(route('portal.integrations.webhooks.index'))
            ->assertOk()
            ->assertSee('An invoice was made by a schedule')
            ->assertSee('A scheduled invoice could not be made')
            ->assertDontSee('An e-invoice was delivered to the buyer');
    }

    public function test_an_endpoint_is_changed_and_removed(): void
    {
        $endpoint = $this->endpoint();

        $this->asAdmin()->post(route('portal.integrations.webhooks.update', $endpoint->id), ['url' => 'https://203.0.113.21/new', 'events' => ['invoice.issued'], 'is_active' => '0'])
            ->assertSessionHas('success');
        $endpoint->refresh();
        $this->assertSame(['https://203.0.113.21/new', ['invoice.issued'], false], [$endpoint->url, $endpoint->events, $endpoint->is_active]);

        $this->asAdmin()->post(route('portal.integrations.webhooks.destroy', $endpoint->id))->assertRedirect(route('portal.integrations.webhooks.index'));
        $removed = Webhook::withoutTenantScope(fn () => Webhook::withTrashed()->find($endpoint->id));
        $this->assertTrue($removed->trashed());
        $this->assertSame($this->admin->id, $removed->deleted_by);
    }

    public function test_rotating_shows_the_new_secret(): void
    {
        $endpoint = $this->endpoint();
        $old = $endpoint->secret;

        $this->asAdmin()->post(route('portal.integrations.webhooks.rotate', $endpoint->id))->assertSessionHas('success');

        $new = $endpoint->fresh()->secret;
        $this->assertNotSame($old, $new);
        $this->show($endpoint)->assertSee($new)->assertDontSee($old);
    }

    public function test_a_test_event_is_delivered(): void
    {
        $endpoint = $this->endpoint();

        $this->asAdmin()->post(route('portal.integrations.webhooks.test', $endpoint->id))->assertSessionHas('success');

        Http::assertSent(fn ($request): bool => $request->url() === self::URL && $request['event'] === 'webhook.test');
        $this->show($endpoint)->assertSee('A test event')->assertSee('Delivered (200)');
    }

    public function test_retries_read_as_one_delivery(): void
    {
        $endpoint = $this->endpoint();

        foreach ([[0, false], [503, false], [200, true]] as $minute => [$status, $success]) {
            $this->travelTo(now()->addMinutes($minute + 1));
            WebhookLog::create(['webhook_id' => $endpoint->id, 'event' => 'invoice.cleared', 'payload' => ['id' => 'delivery-1', 'event' => 'invoice.cleared', 'data' => ['invoice_number' => 'INV-77']],
                'response_status' => $status, 'response_body' => '', 'duration_ms' => 120, 'success' => $success]);
        }

        $this->show($endpoint)
            ->assertViewHas('deliveries', fn (array $deliveries): bool => count($deliveries) === 1 && $deliveries[0]['attempts'] === 3 && $deliveries[0]['delivered'])
            ->assertSee('Delivered (200)');
    }

    public function test_personal_data_is_not_shown(): void
    {
        $endpoint = $this->endpoint();
        WebhookLog::create(['webhook_id' => $endpoint->id, 'event' => 'invoice.rejected',
            'payload' => ['id' => 'delivery-2', 'event' => 'invoice.rejected', 'data' => [
                'invoice_number' => 'INV-78', 'total_amount' => '115.00', 'buyer_name' => 'Huda Al-Harbi',
                'error_message' => 'Buyer 310122393500003 refused', 'e_invoice' => ['receiver' => '0235:1007654321', 'status' => 'failed'],
            ]],
            'response_status' => 400, 'response_body' => 'Unknown customer huda@example.com, phone +966 50 123 4567', 'duration_ms' => 80, 'success' => false]);

        $this->show($endpoint)
            ->assertSee('INV-78')
            ->assertSee('115.00')
            ->assertSee('Refused (400)')
            ->assertDontSee('Huda Al-Harbi')
            ->assertDontSee('310122393500003')
            ->assertDontSee('1007654321')
            ->assertDontSee('huda@example.com')
            ->assertDontSee('123 4567');
    }

    public function test_only_administrators_reach_webhooks(): void
    {
        $member = $this->memberAs('member');
        $endpoint = $this->endpoint();

        $this->actingAs($member)->withSession(['portal_org_id' => $this->acme->id])
            ->get(route('portal.integrations.webhooks.index'))->assertForbidden();
        $this->actingAs($member)->withSession(['portal_org_id' => $this->acme->id])
            ->post(route('portal.integrations.webhooks.rotate', $endpoint->id))->assertForbidden();
    }

    public function test_another_organizations_endpoint_is_unfindable(): void
    {
        $rival = Organization::create(['name' => 'Rival', 'country' => 'SA', 'status' => 'active']);
        $theirs = Webhook::withoutTenantScope(fn () => Webhook::create(['org_id' => $rival->id, 'url' => self::URL, 'secret' => str_repeat('s', 64), 'events' => ['invoice.cleared'], 'is_active' => true]));

        $this->show($theirs)->assertNotFound();
        $this->asAdmin()->post(route('portal.integrations.webhooks.rotate', $theirs->id))->assertNotFound();
        $this->assertSame(str_repeat('s', 64), $theirs->fresh()->secret);
    }

    public function test_a_plan_without_webhooks_cannot_add(): void
    {
        License::withoutGlobalScopes()->where('org_id', $this->acme->id)->get()->each->revoke('Plan changed');
        $this->licence(LicenseTier::Starter);

        $this->asAdmin()->get(route('portal.integrations.webhooks.index'))->assertOk()->assertSee('You cannot add a webhook now');
        $this->asAdmin()->post(route('portal.integrations.webhooks.store'), ['url' => self::URL, 'events' => ['invoice.cleared']])->assertSessionHas('error');
        $this->assertSame(0, Webhook::withoutTenantScope(fn () => Webhook::query()->count()));
    }

    private function endpoint(): Webhook
    {
        $this->asAdmin()->post(route('portal.integrations.webhooks.store'), ['url' => self::URL, 'events' => ['invoice.cleared']])->assertSessionHas('success');

        return Webhook::withoutTenantScope(fn () => Webhook::query()->where('org_id', $this->acme->id)->latest()->firstOrFail());
    }

    private function show(Webhook $endpoint): TestResponse
    {
        return $this->asAdmin()->get(route('portal.integrations.webhooks.show', $endpoint->id));
    }

    private function licence(LicenseTier $tier): void
    {
        License::createWithCredentials(['org_id' => $this->acme->id, 'organization_name' => 'Acme Trading', 'contact_email' => 'ops@acme.test', 'tier' => $tier]);
    }

    private function memberAs(string $role): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }

    private function asAdmin(): self
    {
        return $this->actingAs($this->admin)->withSession(['portal_org_id' => $this->acme->id]);
    }
}
