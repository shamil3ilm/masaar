<?php

declare(strict_types=1);

namespace Tests\Feature\Webhook;

use App\Domains\Auth\Models\User;
use App\Domains\Organization\Models\Organization;
use App\Domains\Webhook\Models\Webhook;
use App\Domains\Webhook\Models\WebhookLog;
use App\Domains\Webhook\Services\WebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * A webhook is a URL a tenant chooses and the platform calls.
 *
 * It was checked only for being a URL, and delivered with a plain HTTP
 * client that follows redirects. So a tenant could register
 * http://169.254.169.254/latest/meta-data/ - the cloud metadata service and
 * its credentials - or the Redis or MySQL ports on the private network, and
 * have the platform make the request from inside the trust boundary, with the
 * response body written to a log the tenant can read.
 *
 * Literal addresses throughout: a hostname would need DNS the test cannot
 * control. 203.0.113.0/24 and 198.51.100.0/24 are documentation ranges that
 * PHP treats as public.
 */
class WebhookSsrfTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);

        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);

        $this->token = JWTAuth::claims(['org_id' => $this->acme->id, 'role' => 'admin'])->fromUser($user);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function internal(): array
    {
        return [
            'cloud metadata' => ['https://169.254.169.254/latest/meta-data/'],
            'loopback' => ['https://127.0.0.1:6379/'],
            'private network' => ['https://10.0.0.5/hooks'],
            'home network' => ['https://192.168.1.10/hooks'],
            'docker network' => ['https://172.18.0.3:3306/'],
            'plain http' => ['http://203.0.113.10/hooks'],
        ];
    }

    #[DataProvider('internal')]
    public function test_an_internal_url_is_refused(string $url): void
    {
        $this->withToken($this->token)
            ->postJson('/api/webhooks', ['url' => $url, 'events' => ['invoice.cleared']])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('url', 'error.details');

        $this->assertSame(0, Webhook::acrossTenants()->count());
    }

    #[DataProvider('internal')]
    public function test_an_update_cannot_point_inward(string $url): void
    {
        $webhook = $this->endpoint('https://203.0.113.10/hooks');

        $this->withToken($this->token)
            ->putJson('/api/webhooks/'.$webhook->id, ['url' => $url])
            ->assertStatus(422);

        $this->assertSame('https://203.0.113.10/hooks', $webhook->fresh()->url);
    }

    public function test_a_public_url_is_accepted(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/webhooks', ['url' => 'https://203.0.113.10/hooks', 'events' => ['invoice.cleared']])
            ->assertCreated();
    }

    /**
     * Checked again when sent, not only when saved: an endpoint registered
     * before this check existed, or a name whose DNS has since changed, would
     * otherwise be called wherever it points now.
     */
    public function test_an_internal_url_is_never_called(): void
    {
        Http::fake();

        $webhook = $this->endpoint('https://169.254.169.254/latest/meta-data/');

        $this->assertFalse(app(WebhookService::class)->deliver($webhook, 'invoice.cleared', ['ok' => true]));

        Http::assertNothingSent();

        $log = WebhookLog::where('webhook_id', $webhook->id)->first();

        $this->assertNotNull($log, 'a refused delivery should still be on the endpoint\'s log');
        $this->assertStringContainsString('non-public address', (string) $log->response_body);
    }

    /**
     * A public endpoint cannot bounce the request inward with a redirect.
     */
    public function test_a_redirect_is_not_followed(): void
    {
        Http::fake([
            '203.0.113.10/*' => Http::response('', 302, ['Location' => 'https://169.254.169.254/latest/meta-data/']),
            '169.254.169.254/*' => Http::response('{"AccessKeyId":"leaked"}', 200),
        ]);

        $webhook = $this->endpoint('https://203.0.113.10/hooks');

        $this->assertFalse(app(WebhookService::class)->deliver($webhook, 'invoice.cleared', ['ok' => true]));

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '169.254.169.254'));
    }

    private function endpoint(string $url): Webhook
    {
        $webhook = (new Webhook)->forceFill([
            'org_id' => $this->acme->id,
            'url' => $url,
            'events' => ['invoice.cleared'],
            'secret' => 'whsec_'.str_repeat('a', 32),
            'is_active' => true,
        ]);
        $webhook->save();

        return $webhook;
    }
}
