<?php

declare(strict_types=1);

namespace Tests\Feature\Webhook;

use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use App\Domains\Webhook\Models\Webhook;
use App\Domains\Webhook\Services\WebhookService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A new signing secret without a gap.
 *
 * Rotated, the old secret stopped verifying at once, so every delivery
 * between the rotation and the receiver's deploy of the new secret was
 * refused. For Webhook::ROTATION_OVERLAP_HOURS after a rotation each
 * delivery carries two signatures, one per secret, so a receiver holding
 * either verifies it. A secret that leaked is ended at once instead.
 */
class WebhookRotationTest extends TestCase
{
    use RefreshDatabase;

    private Webhook $webhook;

    /** @var array<string, string> */
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00:00', 'UTC'));
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $organization = Organization::create(['name' => 'Acme', 'country' => 'SA', 'status' => 'active']);
        $this->webhook = Webhook::acrossTenants()->create([
            'org_id' => $organization->id, 'url' => 'https://203.0.113.20/hooks', 'secret' => 'old-secret', 'events' => ['*'], 'is_active' => true,
        ]);

        $issued = License::createWithCredentials(['org_id' => $organization->id, 'organization_name' => 'Acme', 'contact_email' => 'erp@example.test', 'tier' => 'enterprise']);
        License::withoutGlobalScopes()->whereKey($issued['license']->id)->update(['status' => 'active', 'scopes' => json_encode(['webhook.manage'])]);
        $this->headers = ['X-API-Key' => $issued['api_key'], 'X-API-Secret' => $issued['api_secret']];
    }

    public function test_either_secret_verifies_during_rotation(): void
    {
        $answer = $this->rotate([])->assertOk();
        $new = $answer->json('data.secret');
        $this->assertSame('2026-11-02T09:00:00+00:00', $answer->json('data.previous_valid_until'));

        $this->deliver();

        $this->assertVerifiesWith(['old-secret', $new]);
    }

    public function test_the_old_secret_lapses(): void
    {
        $new = $this->rotate([])->json('data.secret');

        $this->travel(25)->hours();
        $this->deliver();

        $this->assertVerifiesWith([$new], refuses: 'old-secret');
    }

    public function test_a_leaked_secret_ends_now(): void
    {
        $answer = $this->rotate(['revoke_previous' => true])->assertOk()->assertJsonPath('data.previous_valid_until', null);

        $this->deliver();

        $this->assertVerifiesWith([$answer->json('data.secret')], refuses: 'old-secret');
    }

    public function test_the_old_secret_is_encrypted(): void
    {
        $this->rotate([]);

        $stored = (string) DB::table('webhooks')->where('id', $this->webhook->id)->value('previous_secret');
        $this->assertNotSame('', $stored);
        $this->assertStringNotContainsString('old-secret', $stored);
        $this->assertArrayNotHasKey('previous_secret', $this->webhook->fresh()->toArray());
    }

    /** @param  array<string, mixed>  $body */
    private function rotate(array $body): TestResponse
    {
        return $this->postJson("/api/v1/webhooks/{$this->webhook->id}/rotate-secret", $body, $this->headers);
    }

    private function deliver(): void
    {
        app(WebhookService::class)->attempt($this->webhook->fresh(), 'webhook.test', ['message' => 'hello'], 'delivery-1', lastTry: true);
    }

    /** @param  list<string>  $secrets */
    private function assertVerifiesWith(array $secrets, ?string $refuses = null): void
    {
        $service = app(WebhookService::class);

        Http::assertSent(function (Request $request) use ($service, $secrets, $refuses): bool {
            $signatures = array_map('trim', explode(',', $request->header('X-Webhook-Signature')[0]));
            $this->assertCount(count($secrets), $signatures);

            foreach ($secrets as $secret) {
                $this->assertTrue($this->anyVerifies($service, $request->body(), $signatures, $secret), 'a receiver holding '.$secret.' verifies it');
            }

            if ($refuses !== null) {
                $this->assertFalse($this->anyVerifies($service, $request->body(), $signatures, $refuses), $refuses.' no longer verifies');
            }

            return true;
        });
    }

    /** @param  list<string>  $signatures */
    private function anyVerifies(WebhookService $service, string $body, array $signatures, string $secret): bool
    {
        foreach ($signatures as $signature) {
            if ($service->verifySignature($body, $signature, $secret)) {
                return true;
            }
        }

        return false;
    }
}
