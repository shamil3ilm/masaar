<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use App\Domains\Webhook\Models\Webhook;
use App\Domains\Webhook\Models\WebhookLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Customer endpoints, and why their deliveries failed.
 *
 * Every delivery attempt has been written to webhook_logs since the beginning
 * - the status the receiver answered, its body, how long it took - and there
 * was nowhere to read it. "Our webhook stopped working" was answered with a
 * psql prompt, or not at all.
 *
 * Every assertion runs inside asRequest(). TenantScope stands down in console
 * context, and PHPUnit is console, so a plain feature test would exercise the
 * unscoped path and pass whether or not the screen asks to be cross-tenant.
 * A platform admin holds no tenant, so inside a request the scope matches
 * nothing and the page is empty unless WebhookReport steps past it
 * deliberately - which is the property test_endpoints_across_tenants_appear
 * exists to hold.
 */
class WebhookListTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoints_are_listed(): void
    {
        $this->endpoint($this->organization('Acme Trading'), 'https://acme.test/hooks/einvoicing');

        $this->asRequest(fn () => $this->actingAs($this->admin())
            ->get('/admin/webhooks')
            ->assertOk()
            ->assertSee('https://acme.test/hooks/einvoicing')
            ->assertSee('Acme Trading'));
    }

    /**
     * The console reads across taxpayers, which is the whole reason it is
     * worth having: the person answering "did our events arrive" does not know
     * which tenant asked, and should not have to switch between them to find
     * out.
     */
    public function test_endpoints_across_tenants_appear(): void
    {
        $this->endpoint($this->organization('Acme Trading'), 'https://acme.test/hooks/einvoicing');
        $this->endpoint($this->organization('Rawafid Logistics'), 'https://rawafid.test/webhooks/zatca');

        $this->asRequest(fn () => $this->actingAs($this->admin())
            ->get('/admin/webhooks')
            ->assertOk()
            ->assertSee('https://acme.test/hooks/einvoicing')
            ->assertSee('https://rawafid.test/webhooks/zatca'));
    }

    /**
     * The status the receiver answered and the body it returned are the whole
     * diagnosis. A list of timestamps marked "failed" would send the reader
     * back to the database.
     */
    public function test_the_log_shows_deliveries(): void
    {
        $webhook = $this->endpoint($this->organization('Acme Trading'), 'https://acme.test/hooks/einvoicing');

        WebhookLog::create([
            'webhook_id' => $webhook->id,
            'event' => 'invoice.cleared',
            'payload' => ['event' => 'invoice.cleared', 'data' => ['invoice_number' => 'INV-9001']],
            'response_status' => 503,
            'response_body' => 'upstream connect error',
            'duration_ms' => 1842,
            'success' => false,
        ]);

        $this->asRequest(fn () => $this->actingAs($this->admin())
            ->get('/admin/webhooks/'.$webhook->id)
            ->assertOk()
            ->assertSee('invoice.cleared')
            ->assertSee('503')
            ->assertSee('upstream connect error'));
    }

    /**
     * A truncated log says that it is truncated.
     *
     * The report returns the newest fifty attempts and the heading read "50
     * most recent attempts" whether the endpoint had had fifty or five
     * thousand. This is the screen opened to answer "our webhook stopped
     * working", so a reader who takes the list for the whole history
     * concludes the endpoint was tried fifty times and stopped - when the
     * truth may be that it has been failing for weeks.
     *
     * The same shape as the alerts screen, which caps at two hundred and
     * shows the real total beside it.
     */
    public function test_a_truncated_log_says_so(): void
    {
        $webhook = $this->endpoint($this->organization('Acme Trading'), 'https://acme.test/hooks/einvoicing');

        foreach (range(1, 51) as $n) {
            WebhookLog::create([
                'webhook_id' => $webhook->id,
                'event' => 'invoice.cleared',
                'payload' => ['event' => 'invoice.cleared', 'data' => ['invoice_number' => 'INV-'.$n]],
                'response_status' => 503,
                'response_body' => 'upstream connect error',
                'duration_ms' => 10,
                'success' => false,
            ]);
        }

        $this->asRequest(fn () => $this->actingAs($this->admin())
            ->get('/admin/webhooks/'.$webhook->id)
            ->assertOk()
            ->assertSee('51 attempts')
            ->assertSee('showing the 50 most recent'));
    }

    /**
     * And a log shorter than the cap claims no truncation.
     */
    public function test_a_complete_log_claims_nothing(): void
    {
        $webhook = $this->endpoint($this->organization('Acme Trading'), 'https://acme.test/hooks/einvoicing');

        WebhookLog::create([
            'webhook_id' => $webhook->id,
            'event' => 'invoice.cleared',
            'payload' => ['event' => 'invoice.cleared', 'data' => []],
            'response_status' => 200,
            'response_body' => 'ok',
            'duration_ms' => 10,
            'success' => true,
        ]);

        $this->asRequest(fn () => $this->actingAs($this->admin())
            ->get('/admin/webhooks/'.$webhook->id)
            ->assertOk()
            ->assertSee('1 attempt')
            ->assertDontSee('showing the'));
    }

    private function admin(): User
    {
        return User::factory()->platformAdmin()->create();
    }

    private function organization(string $name): Organization
    {
        return Organization::create(['name' => $name, 'country' => 'SA']);
    }

    private function endpoint(Organization $organization, string $url): Webhook
    {
        return Webhook::create([
            'org_id' => $organization->id,
            'url' => $url,
            'secret' => str_repeat('s', 64),
            'events' => ['invoice.cleared', 'invoice.rejected'],
            'is_active' => true,
            'failure_count' => 0,
        ]);
    }
}
