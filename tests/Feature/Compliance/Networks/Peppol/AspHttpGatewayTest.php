<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\Networks\Peppol;

use App\Domains\Compliance\Networks\Peppol\Contracts\AspGateway;
use App\Domains\Compliance\Networks\Peppol\Exceptions\AspRefusedException;
use App\Domains\Compliance\Networks\Peppol\Exceptions\AspUnavailableException;
use App\Domains\Compliance\Networks\Peppol\Gateways\AspError;
use App\Domains\Compliance\Networks\Peppol\Gateways\HttpAspGateway;
use App\Domains\Compliance\Networks\Peppol\Gateways\NullAspGateway;
use App\Domains\Compliance\Networks\Peppol\Gateways\WebhookSignature;
use App\Domains\Compliance\Networks\Peppol\Models\PeppolDocument;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The generic REST gateway: which provider is bound, what it sends, and how
 * it reads an answer - a verdict on the document, or none.
 */
class AspHttpGatewayTest extends TestCase
{
    /** A literal public address: SafeUrl passes it without a DNS lookup. */
    private const BASE = 'https://93.184.215.14/api';

    public function test_gateway_follows_the_configuration(): void
    {
        config(['peppol.asp.base_url' => null]);
        $this->assertInstanceOf(NullAspGateway::class, app(AspGateway::class));

        config(['peppol.asp.base_url' => self::BASE]);
        $this->assertInstanceOf(HttpAspGateway::class, app(AspGateway::class));
    }

    public function test_submit_sends_document_once_keyed(): void
    {
        Http::fake([
            self::BASE.'/oauth/token' => Http::response(['access_token' => 'token-1', 'expires_in' => 3600]),
            self::BASE.'/documents' => Http::response(['id' => 'msg-42'], 201),
        ]);

        $document = $this->document();

        $this->assertSame('msg-42', $this->gateway()->submit($document));

        Http::assertSent(fn (Request $request) => $request->url() === self::BASE.'/documents'
            && $request->hasHeader('Authorization', 'Bearer token-1')
            && $request->hasHeader('Idempotency-Key', 'doc-1')
            && base64_decode($request['document']) === '<Invoice/>'
            && $request['receiver'] === '0235:1000000002');
    }

    public function test_token_is_reused_until_expiry(): void
    {
        Http::fake([
            self::BASE.'/oauth/token' => Http::response(['access_token' => 'token-1', 'expires_in' => 3600]),
            self::BASE.'/documents' => Http::response(['id' => 'msg-42'], 201),
        ]);

        $this->gateway()->submit($this->document());
        $this->gateway()->submit($this->document());

        Http::assertSentCount(3);
    }

    public function test_refusal_carries_the_errors(): void
    {
        Http::fake([
            self::BASE.'/oauth/token' => Http::response(['access_token' => 'token-1']),
            self::BASE.'/documents' => Http::response(['errors' => [
                ['code' => 'BR-AE-01', 'severity' => 'fatal', 'location' => '/Invoice/cac:AccountingCustomerParty', 'message' => 'Buyer endpoint missing'],
                'BR-AE-02: Buyer name missing',
            ]], 422),
        ]);

        try {
            $this->gateway()->submit($this->document());
            $this->fail('A 422 is a refusal.');
        } catch (AspRefusedException $e) {
            $this->assertSame([
                ['code' => 'BR-AE-01', 'severity' => 'fatal', 'field' => '/Invoice/cac:AccountingCustomerParty', 'message' => 'Buyer endpoint missing'],
                ['code' => 'BR-AE-02', 'severity' => 'error', 'field' => null, 'message' => 'Buyer name missing'],
            ], AspError::toArrays($e->errors));
        }
    }

    /**
     * Throttling, an outage and refused credentials say nothing about the
     * document, so it is tried again rather than marked rejected.
     */
    public function test_no_verdict_is_unavailable(): void
    {
        Http::fake([
            self::BASE.'/oauth/token' => Http::response(['access_token' => 'token-1']),
            // 409 is a retry meeting its own idempotency key: the document
            // may already be on the network, so it is not a refusal.
            self::BASE.'/documents' => Http::sequence()->push([], 429)->push([], 503)->push([], 401)->push([], 409),
        ]);

        foreach ([429, 503, 401, 409] as $status) {
            try {
                $this->gateway()->submit($this->document());
                $this->fail("HTTP {$status} is no verdict.");
            } catch (AspUnavailableException $e) {
                $this->assertStringContainsString((string) $status, $e->getMessage());
            }
        }
    }

    /**
     * Neither the client secret nor anything from the token answer reaches
     * an exception that will be stored as the document's last error.
     */
    public function test_token_failure_hides_the_secret(): void
    {
        Http::fake([self::BASE.'/oauth/token' => Http::response(['error' => 'invalid_client client-secret-1'], 401)]);

        try {
            $this->gateway()->submit($this->document());
            $this->fail('No token, no submission.');
        } catch (AspUnavailableException $e) {
            $this->assertStringNotContainsString('client-secret-1', $e->getMessage());
        }
    }

    public function test_private_address_is_never_called(): void
    {
        Http::fake();

        $gateway = new HttpAspGateway('https://127.0.0.1', 'client-1', 'client-secret-1', new WebhookSignature('s'));

        $this->expectException(AspUnavailableException::class);

        try {
            $gateway->submit($this->document());
        } finally {
            Http::assertNothingSent();
        }
    }

    private function gateway(): HttpAspGateway
    {
        return new HttpAspGateway(self::BASE, 'client-1', 'client-secret-1', new WebhookSignature('s'));
    }

    private function document(): PeppolDocument
    {
        return (new PeppolDocument)->forceFill([
            'id' => 'doc-1',
            'xml' => '<Invoice/>',
            'sender_endpoint' => '0235:1000000001',
            'receiver_endpoint' => '0235:1000000002',
        ]);
    }
}
