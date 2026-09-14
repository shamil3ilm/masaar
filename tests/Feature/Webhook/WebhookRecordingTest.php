<?php

declare(strict_types=1);

namespace Tests\Feature\Webhook;

use App\Domains\Organization\Models\Organization;
use App\Domains\Webhook\Models\Webhook;
use App\Domains\Webhook\Models\WebhookLog;
use App\Domains\Webhook\Services\WebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * What a delivery leaves behind: a log entry and the endpoint's health.
 *
 * An endpoint that keeps failing is switched off after ten consecutive
 * failures, and a success forgives the ones before it.
 */
class WebhookRecordingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Acme', 'country' => 'SA']);
    }

    public function test_failed_delivery_is_logged_and_counted(): void
    {
        Http::fake(['*' => Http::response('down', 500)]);
        $webhook = $this->webhook(0);

        $this->assertFalse(app(WebhookService::class)->deliver($webhook, 'invoice.cleared', ['id' => 'x']));

        $stored = $this->reload($webhook);
        $log = WebhookLog::where('webhook_id', $webhook->id)->sole();

        $this->assertSame(1, $stored->failure_count);
        $this->assertTrue($stored->is_active);
        $this->assertFalse((bool) $log->success);
        $this->assertSame(500, (int) $log->response_status);
        $this->assertSame('invoice.cleared', $log->event);
    }

    public function test_tenth_failure_disables_endpoint(): void
    {
        Http::fake(['*' => Http::response('down', 500)]);
        $webhook = $this->webhook(9);

        app(WebhookService::class)->deliver($webhook, 'invoice.cleared', []);

        $stored = $this->reload($webhook);

        $this->assertSame(10, $stored->failure_count);
        $this->assertFalse($stored->is_active);
    }

    public function test_unreachable_endpoint_is_a_failure(): void
    {
        Http::fake(fn () => throw new ConnectionException('refused'));
        $webhook = $this->webhook(2);

        $this->assertFalse(app(WebhookService::class)->deliver($webhook, 'invoice.cleared', []));

        $log = WebhookLog::where('webhook_id', $webhook->id)->sole();

        $this->assertSame(3, $this->reload($webhook)->failure_count);
        $this->assertSame(0, (int) $log->response_status);
        $this->assertSame('refused', $log->response_body);
    }

    public function test_success_forgives_earlier_failures(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $webhook = $this->webhook(4);

        $this->assertTrue(app(WebhookService::class)->deliver($webhook, 'invoice.cleared', []));

        $stored = $this->reload($webhook);

        $this->assertSame(0, $stored->failure_count);
        $this->assertNotNull($stored->last_triggered_at);
        $this->assertTrue((bool) WebhookLog::where('webhook_id', $webhook->id)->sole()->success);
    }

    private function webhook(int $failures): Webhook
    {
        $webhook = (new Webhook)->forceFill([
            'org_id' => $this->organization->id,
            'url' => 'https://erp.test/hooks',
            'secret' => 'shhh',
            'events' => ['invoice.cleared'],
            'is_active' => true,
            'failure_count' => $failures,
        ]);

        $webhook->save();

        return $webhook;
    }

    private function reload(Webhook $webhook): Webhook
    {
        return Webhook::withoutTenantScope(fn () => Webhook::findOrFail($webhook->id));
    }
}
