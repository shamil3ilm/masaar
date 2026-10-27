<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\UnitedArabEmirates;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Document\PeppolPreparer;
use App\Domains\Compliance\Networks\Peppol\Jobs\SendPeppolDocument;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\MatchesOpenapi;
use Tests\TestCase;

/**
 * /api/v1/compliance/ae: a UAE organization's ERP drafting, sending and
 * reading its e-invoices with its own key, as /api/compliance/ae lets a
 * signed-in person - only its own, and only for a UAE organization.
 */
class UaePartnerApiTest extends TestCase
{
    use MatchesOpenapi;
    use PintInvoices;
    use RefreshDatabase;

    private Organization $gulf;

    protected function setUp(): void
    {
        parent::setUp();

        config(['peppol.countries.AE.enabled' => true]);
        Queue::fake();
        $this->gulf = $this->uaeOrganization();
    }

    public function test_draft_send_and_follow(): void
    {
        $headers = $this->licence($this->gulf, ['invoice.submit', 'compliance.status']);

        $id = $this->postJson('/api/v1/compliance/ae/invoices', $this->payload(), $headers)
            ->assertCreated()
            ->assertJsonPath('data.invoice.status', 'draft')
            ->json('data.invoice.id');

        $documentId = $this->postJson("/api/v1/compliance/ae/invoices/{$id}/send", [], $headers)
            ->assertOk()
            ->assertJsonPath('data.invoice_status', 'issued')
            ->json('data.document_id');
        Queue::assertPushed(SendPeppolDocument::class, 1);

        $status = $this->getJson("/api/v1/compliance/ae/invoices/{$id}/status", $headers)
            ->assertOk()
            ->assertJsonPath('data.document_id', $documentId);
        $list = $this->getJson('/api/v1/compliance/ae/documents', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.id', $documentId);
        $one = $this->getJson("/api/v1/compliance/ae/documents/{$documentId}", $headers)
            ->assertOk()
            ->assertJsonPath('data.document.id', $documentId);

        // As docs/openapi.yaml describes them.
        $this->assertResponseMatchesSpec($status, '/api/v1/compliance/ae/invoices/{invoice}/status', 'get');
        $this->assertResponseMatchesSpec($list, '/api/v1/compliance/ae/documents', 'get');
        $this->assertResponseMatchesSpec($this->getJson('/api/v1/compliance/ae/documents?cursor=', $headers), '/api/v1/compliance/ae/documents', 'get');
        $this->assertResponseMatchesSpec($one, '/api/v1/compliance/ae/documents/{document}', 'get');
        $this->assertStringStartsWith('application/xml', (string) $this->get("/api/v1/compliance/ae/documents/{$documentId}/xml", $headers)
            ->assertOk()->headers->get('Content-Type'));
    }

    public function test_documents_are_walked_by_cursor(): void
    {
        $headers = $this->licence($this->gulf, ['compliance.status']);
        $first = app(PeppolPreparer::class)->prepare($this->uaeInvoice($this->gulf));
        $this->travel(1)->minutes();
        $second = app(PeppolPreparer::class)->prepare($this->uaeInvoice($this->gulf));

        $page = $this->getJson('/api/v1/compliance/ae/documents?per_page=1&cursor=', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonMissingPath('data.0.xml');
        $this->getJson('/api/v1/compliance/ae/documents?per_page=1&cursor='.$page->json('meta.next_cursor'), $headers)
            ->assertOk()
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('meta.next_cursor', null);

        $this->getJson('/api/v1/compliance/ae/documents?updated_since='.urlencode(now()->subSeconds(30)->toIso8601String()), $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $second->id);
    }

    public function test_rival_records_are_not_found(): void
    {
        $headers = $this->licence($this->gulf, ['invoice.submit', 'compliance.status']);
        $invoice = $this->uaeInvoice($this->uaeOrganization());
        $document = app(PeppolPreparer::class)->prepare($invoice);

        $this->getJson("/api/v1/compliance/ae/documents/{$document->id}", $headers)->assertNotFound();
        $this->get("/api/v1/compliance/ae/documents/{$document->id}/xml", $headers)->assertNotFound();
        $this->getJson("/api/v1/compliance/ae/invoices/{$invoice->id}/status", $headers)->assertNotFound();
        $this->postJson("/api/v1/compliance/ae/invoices/{$invoice->id}/send", [], $headers)->assertNotFound();
        $this->getJson('/api/v1/compliance/ae/documents', $headers)->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_scopes_are_required(): void
    {
        $reader = $this->licence($this->gulf, ['compliance.status']);

        $this->postJson('/api/v1/compliance/ae/invoices', $this->payload(), $reader)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'AUTH_SCOPE_DENIED');
        $this->getJson('/api/v1/compliance/ae/documents', $this->licence($this->gulf, ['invoice.submit']))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'AUTH_SCOPE_DENIED');
    }

    public function test_another_country_is_refused(): void
    {
        $saudi = Organization::create(['name' => 'Acme', 'country' => 'SA', 'status' => 'active']);

        $this->getJson('/api/v1/compliance/ae/documents', $this->licence($saudi, ['compliance.status']))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    /**
     * @param  list<string>  $scopes
     * @return array<string, string>
     */
    private function licence(Organization $organization, array $scopes): array
    {
        $issued = License::createWithCredentials([
            'org_id' => $organization->id, 'organization_name' => $organization->name, 'contact_email' => 'erp@example.test', 'tier' => 'enterprise',
        ]);
        License::withoutGlobalScopes()->whereKey($issued['license']->id)->update(['status' => 'active', 'scopes' => json_encode($scopes)]);

        return ['X-API-Key' => $issued['api_key'], 'X-API-Secret' => $issued['api_secret']];
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'payment_means_code' => '30',
            'buyer_name' => 'Noor Electronics LLC',
            'buyer_vat_number' => '100765432100003',
            'buyer_address' => ['street' => 'Khalifa Street', 'city' => 'Abu Dhabi'],
            'buyer_endpoint' => '0235:1007654321',
            'buyer_subdivision' => 'AUH',
            'lines' => [[
                'item_name' => 'Consulting', 'quantity' => 2, 'unit_code' => 'DAY', 'unit_price' => 500, 'tax_category' => 'S', 'tax_rate' => 5,
            ]],
        ];
    }
}
