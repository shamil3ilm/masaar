<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\Networks\Peppol;

use App\Domains\Compliance\Networks\Peppol\Contracts\AspGateway;
use App\Domains\Compliance\Networks\Peppol\Gateways\FakeAspGateway;
use App\Domains\Compliance\Networks\Peppol\Gateways\NullAspGateway;
use App\Domains\Compliance\Networks\Peppol\Gateways\WebhookSignature;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The provider's callbacks are public routes, so the signature is all that
 * stands between the internet and every document's status. Unsigned, wrongly
 * signed and stale callbacks change nothing.
 */
class WebhookSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'fake-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(AspGateway::class, new FakeAspGateway(self::SECRET));
    }

    public function test_unsigned_callback_is_refused(): void
    {
        $document = $this->sentDocument();

        $this->postJson('/api/peppol/webhooks/status', $this->delivered())->assertUnauthorized();

        $this->assertSame('sent', $document->fresh()->status);
    }

    public function test_wrong_secret_is_refused(): void
    {
        $document = $this->sentDocument();

        $this->signed('/api/peppol/webhooks/status', $this->delivered(), secret: 'not-the-secret')
            ->assertUnauthorized();

        $this->assertSame('sent', $document->fresh()->status);
    }

    /**
     * A body changed after signing does not carry the signature with it.
     */
    public function test_tampered_body_is_refused(): void
    {
        $document = $this->sentDocument();
        $timestamp = (string) time();
        $signature = (new WebhookSignature(self::SECRET))->sign($timestamp, '{"message_id":"other"}');

        $this->postJson('/api/peppol/webhooks/status', $this->delivered(), [
            WebhookSignature::SIGNATURE_HEADER => $signature,
            WebhookSignature::TIMESTAMP_HEADER => $timestamp,
        ])->assertUnauthorized();

        $this->assertSame('sent', $document->fresh()->status);
    }

    /**
     * A captured callback, correctly signed, replayed after the tolerance.
     */
    public function test_stale_replay_is_refused(): void
    {
        $document = $this->sentDocument();
        $stale = time() - WebhookSignature::TOLERANCE - 1;

        $this->signed('/api/peppol/webhooks/status', $this->delivered(), timestamp: $stale)->assertUnauthorized();
        $this->signed('/api/peppol/webhooks/inbound', ['message_id' => 'x'], timestamp: $stale)->assertUnauthorized();

        $this->assertSame('sent', $document->fresh()->status);
    }

    public function test_signed_callback_is_accepted(): void
    {
        $document = $this->sentDocument();

        $this->signed('/api/peppol/webhooks/status', $this->delivered())->assertOk();

        $this->assertSame('delivered', $document->fresh()->status);
    }

    /**
     * With no secret configured nothing can be verified, so nothing is
     * believed - and with no provider there is nobody to call back.
     */
    public function test_missing_secret_refuses_everything(): void
    {
        $this->sentDocument();

        $this->app->instance(AspGateway::class, new FakeAspGateway(null));
        $this->signed('/api/peppol/webhooks/status', $this->delivered())->assertUnauthorized();

        $this->app->instance(AspGateway::class, new NullAspGateway);
        $this->signed('/api/peppol/webhooks/status', $this->delivered())->assertUnauthorized();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function signed(string $uri, array $body, ?int $timestamp = null, string $secret = self::SECRET): TestResponse
    {
        $json = (string) json_encode($body);
        $timestamp = (string) ($timestamp ?? time());

        return $this->call('POST', $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => (new WebhookSignature($secret))->sign($timestamp, $json),
            'HTTP_X_SIGNATURE_TIMESTAMP' => $timestamp,
        ], $json);
    }

    /**
     * @return array<string, string>
     */
    private function delivered(): array
    {
        return ['message_id' => 'msg-1', 'mls_code' => 'AP'];
    }

    private function sentDocument(): PeppolDocument
    {
        $organization = Organization::create(['name' => 'Gulf Trading', 'country' => 'AE']);

        return PeppolDocument::acrossTenants()->create([
            'org_id' => $organization->id,
            'direction' => PeppolDocument::DIRECTION_OUT,
            'country' => 'AE',
            'customization_id' => 'urn:peppol:pint:billing-1@ae-1',
            'profile_id' => 'urn:peppol:bis:billing',
            'document_type' => 'Invoice',
            'xml' => '<Invoice/>',
            'status' => 'sent',
            'asp_message_id' => 'msg-1',
        ]);
    }
}
