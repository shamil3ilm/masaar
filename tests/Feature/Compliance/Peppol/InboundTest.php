<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\Peppol;

use App\Domains\Compliance\Peppol\Contracts\AspGateway;
use App\Domains\Compliance\Peppol\Gateways\FakeAspGateway;
use App\Domains\Compliance\Peppol\Gateways\WebhookSignature;
use App\Domains\Compliance\Peppol\Models\PeppolDocument;
use App\Domains\Organization\Models\ComplianceProfile;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Documents other suppliers send to our organizations, delivered by the
 * provider. Each lands with the organization whose endpoint it was sent to,
 * once, and never with anybody else.
 */
class InboundTest extends TestCase
{
    use RefreshDatabase;

    private const XML = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"'
        .' xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">'
        .'<cbc:CustomizationID>urn:peppol:pint:billing-1@ae-1</cbc:CustomizationID>'
        .'<cbc:ProfileID>urn:peppol:bis:billing</cbc:ProfileID>'
        .'<cbc:ID>SUP-001</cbc:ID></Invoice>';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(AspGateway::class, new FakeAspGateway('inbound-secret'));
    }

    public function test_document_stored_for_receiving_organization(): void
    {
        $buyer = $this->organizationAt('0235:1000000002');
        $other = $this->organizationAt('0235:1000000003');

        $id = $this->deliver('msg-in-1', 'iso6523-actorid-upis::0235:1000000002')
            ->assertOk()
            ->json('data.id');

        $document = PeppolDocument::withoutTenantScope(fn () => PeppolDocument::query()->findOrFail($id));
        $this->assertSame($buyer->id, $document->org_id);
        $this->assertNotSame($other->id, $document->org_id);
        $this->assertSame('in', $document->direction);
        $this->assertSame('received', $document->status);
        $this->assertNull($document->invoice_id);
        $this->assertSame(self::XML, $document->xml);
        $this->assertSame('urn:peppol:pint:billing-1@ae-1', $document->customization_id);
        $this->assertSame('urn:peppol:bis:billing', $document->profile_id);
        $this->assertSame('Invoice', $document->document_type);
        $this->assertSame('0235:1000000001', $document->sender_endpoint);
        $this->assertSame('0235:1000000002', $document->receiver_endpoint);
    }

    /**
     * The provider retries a callback it thinks failed.
     */
    public function test_redelivery_is_stored_once(): void
    {
        $this->organizationAt('0235:1000000002');

        $first = $this->deliver('msg-in-1', '0235:1000000002')->assertOk()->json('data.id');
        $second = $this->deliver('msg-in-1', '0235:1000000002')->assertOk()->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, PeppolDocument::withoutTenantScope(fn () => PeppolDocument::query()->count()));
    }

    public function test_unknown_receiver_is_not_stored(): void
    {
        $this->organizationAt('0235:1000000002');

        $this->deliver('msg-in-1', '0235:9999999999')->assertNotFound();

        $this->assertSame(0, PeppolDocument::withoutTenantScope(fn () => PeppolDocument::query()->count()));
    }

    /**
     * Two organizations claiming one endpoint: choosing either could hand a
     * purchase invoice to the wrong taxpayer.
     */
    public function test_shared_endpoint_is_not_stored(): void
    {
        $this->organizationAt('0235:1000000002');
        $this->organizationAt('0235:1000000002');

        $this->deliver('msg-in-1', '0235:1000000002')->assertNotFound();

        $this->assertSame(0, PeppolDocument::withoutTenantScope(fn () => PeppolDocument::query()->count()));
    }

    /**
     * A Saudi profile is not a Peppol participant, whatever its settings say.
     */
    public function test_saudi_profile_does_not_receive(): void
    {
        $organization = Organization::create(['name' => 'Riyadh Co', 'country' => 'SA']);
        ComplianceProfile::withoutTenantScope(fn () => ComplianceProfile::create([
            'org_id' => $organization->id,
            'jurisdiction' => 'SA',
            'engine' => 'fatoora',
            'status' => 'active',
            'settings' => ['endpoint_id' => '0235:1000000002'],
        ]));

        $this->deliver('msg-in-1', '0235:1000000002')->assertNotFound();
    }

    public function test_unsigned_delivery_is_refused(): void
    {
        $this->organizationAt('0235:1000000002');

        $this->postJson('/api/peppol/webhooks/inbound', $this->body('msg-in-1', '0235:1000000002'))
            ->assertUnauthorized();

        $this->assertSame(0, PeppolDocument::withoutTenantScope(fn () => PeppolDocument::query()->count()));
    }

    private function deliver(string $messageId, string $receiver): TestResponse
    {
        $json = (string) json_encode($this->body($messageId, $receiver));
        $timestamp = (string) time();

        return $this->call('POST', '/api/peppol/webhooks/inbound', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => (new WebhookSignature('inbound-secret'))->sign($timestamp, $json),
            'HTTP_X_SIGNATURE_TIMESTAMP' => $timestamp,
        ], $json);
    }

    /**
     * @return array<string, string>
     */
    private function body(string $messageId, string $receiver): array
    {
        return [
            'message_id' => $messageId,
            'document' => base64_encode(self::XML),
            'sender' => '0235:1000000001',
            'receiver' => $receiver,
        ];
    }

    private function organizationAt(string $endpoint): Organization
    {
        $organization = Organization::create(['name' => 'Gulf Trading', 'country' => 'AE']);

        ComplianceProfile::withoutTenantScope(fn () => ComplianceProfile::create([
            'org_id' => $organization->id,
            'jurisdiction' => 'AE',
            'engine' => 'peppol',
            'status' => 'active',
            'settings' => ['endpoint_id' => $endpoint],
        ]));

        return $organization;
    }
}
