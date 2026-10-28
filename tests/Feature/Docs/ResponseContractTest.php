<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use App\Domains\Webhook\Models\Webhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as SentRequest;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\MatchesOpenapi;
use Tests\TestCase;

/**
 * The answers docs/openapi.yaml describes are the answers the API gives.
 *
 * The description names what the main endpoints put in `data` (an invoice,
 * a webhook delivery; the pipeline's are held in QuickstartExamplesTest and
 * the UAE's in UaePartnerApiTest). Each is sent here through the kernel and
 * its answer checked against the schema the description gives its status,
 * so a renamed or retyped key fails here before it fails an integration.
 */
class ResponseContractTest extends TestCase
{
    use MatchesOpenapi;
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $headers;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create(['name' => 'Acme', 'country' => 'SA', 'status' => 'active', 'vat_number' => '300000000000003']);
        License::create([
            'org_id' => $this->organization->id, 'api_key' => 'cp_test_contract', 'api_secret_hash' => License::hashSecret('contract-secret'),
            'organization_name' => 'Acme', 'contact_email' => 'ops@example.test', 'environment' => 'sandbox',
            'tier' => 'enterprise', 'status' => 'active', 'scopes' => ['invoice.read', 'invoice.submit', 'webhook.manage'],
        ]);
        $this->headers = ['X-API-Key' => 'cp_test_contract', 'X-API-Secret' => 'contract-secret'];
    }

    public function test_invoice_answers_match_the_spec(): void
    {
        $created = $this->postJson('/api/v1/invoices', $this->invoice(), [...$this->headers, 'Idempotency-Key' => 'contract-1'])->assertCreated();
        $this->assertResponseMatchesSpec($created, '/api/v1/invoices', 'post');
        $id = $created->json('data.invoice.id');

        $replayed = $this->postJson('/api/v1/invoices', $this->invoice(), [...$this->headers, 'Idempotency-Key' => 'contract-1'])->assertOk();
        $this->assertResponseMatchesSpec($replayed, '/api/v1/invoices', 'post');

        $this->assertResponseMatchesSpec($this->getJson('/api/v1/invoices/'.$id, $this->headers)->assertOk(), '/api/v1/invoices/{invoice}', 'get');
        $updated = $this->putJson('/api/v1/invoices/'.$id, [...$this->invoice(), 'buyer_name' => 'Another buyer'], $this->headers);
        $this->assertSame(200, $updated->status(), (string) $updated->getContent());
        $this->assertResponseMatchesSpec($updated, '/api/v1/invoices/{invoice}', 'put');
        $this->assertResponseMatchesSpec($this->getJson('/api/v1/invoices', $this->headers)->assertOk(), '/api/v1/invoices', 'get');
        $this->assertResponseMatchesSpec($this->getJson('/api/v1/invoices?cursor=', $this->headers)->assertOk(), '/api/v1/invoices', 'get');
    }

    public function test_a_webhook_delivery_matches_the_spec(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);
        $webhook = Webhook::acrossTenants()->create([
            'org_id' => $this->organization->id, 'url' => 'https://203.0.113.20/hooks', 'secret' => 'shhh', 'events' => ['*'], 'is_active' => true,
        ]);

        $this->postJson("/api/v1/webhooks/{$webhook->id}/test", [], $this->headers)->assertOk();

        Http::assertSent(function (SentRequest $request): bool {
            $this->assertMatchesSchema('WebhookDelivery', $request->data(), 'delivery');

            return true;
        });
    }

    /** The check is not vacuous: a retyped or missing key is caught. */
    public function test_a_wrong_shape_is_caught(): void
    {
        $this->assertNotSame([], $this->schemaProblems(['$ref' => '#/components/schemas/Invoice'], ['id' => 'x', 'org_id' => 'y', 'status' => 'shipped'], '$'));
        $this->assertNotSame([], $this->schemaProblems(['$ref' => '#/components/schemas/Invoice'], ['id' => 'x', 'status' => 'draft'], '$'));
        $this->assertNotSame([], $this->schemaProblems(['$ref' => '#/components/schemas/PipelineResult'], ['invoice_id' => 'x', 'totals' => '138.00'], '$'));
    }

    /** @return array<string, mixed> */
    private function invoice(): array
    {
        return [
            'invoice_number' => 'INV-CONTRACT-1',
            'type' => 'simplified',
            'issue_date' => now()->toDateString(),
            'buyer_name' => 'Buyer',
            'buyer_name_ar' => 'المشتري',
            'lines' => [['description' => 'Item', 'description_ar' => 'صنف', 'quantity' => 1, 'unit_price' => 100]],
        ];
    }
}
