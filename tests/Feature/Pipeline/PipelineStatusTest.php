<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/v1/pipeline/status/{invoiceId}: what an ERP reads back.
 */
class PipelineStatusTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);

        $issued = License::createWithCredentials([
            'org_id' => $this->acme->id,
            'organization_name' => 'Acme',
            'contact_email' => 'acme@example.test',
            'tier' => 'starter',
            'scopes' => ['compliance.status'],
        ]);

        $this->headers = [
            'X-API-Key' => $issued['api_key'],
            'X-API-Secret' => $issued['api_secret'],
        ];
    }

    public function test_status_reports_the_invoice(): void
    {
        $invoice = $this->invoice($this->acme);

        $response = $this->getJson("/api/v1/pipeline/status/{$invoice->id}", $this->headers)
            ->assertOk()
            ->assertJsonPath('message', 'Invoice status retrieved')
            ->assertJsonPath('data.invoice_id', $invoice->id)
            ->assertJsonPath('data.uuid', $invoice->id)
            ->assertJsonPath('data.invoice_number', 'INV-1')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.type', 'standard')
            ->assertJsonPath('data.issue_date', now()->toDateString());

        $this->assertSame([
            'invoice_id', 'uuid', 'invoice_number', 'status', 'type', 'hash', 'qr_code', 'signed_xml',
            'cleared_xml', 'zatca_response', 'totals', 'issue_date', 'created_at', 'updated_at',
        ], array_keys($response->json('data')));
        $this->assertSame(['subtotal', 'discount_amount', 'tax_amount', 'total'], array_keys($response->json('data.totals')));
    }

    public function test_other_tenant_invoice_not_found(): void
    {
        $rival = Organization::create(['name' => 'Rival', 'country' => 'SA']);
        $theirs = $this->invoice($rival);

        $this->getJson("/api/v1/pipeline/status/{$theirs->id}", $this->headers)->assertNotFound();
    }

    /**
     * A standard invoice is the buyer's once ZATCA clears it: before that the
     * signed XML and QR are withheld, as the portal withholds them.
     */
    public function test_uncleared_standard_document_is_withheld(): void
    {
        $invoice = $this->invoice($this->acme, ['status' => 'issued', 'hash' => 'aGFzaA==', 'qr_code' => 'UVI=', 'signed_xml' => '<Invoice/>']);

        $this->getJson("/api/v1/pipeline/status/{$invoice->id}", $this->headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'issued')
            ->assertJsonPath('data.qr_code', null)
            ->assertJsonPath('data.signed_xml', null)
            ->assertJsonPath('data.cleared_xml', null);
    }

    public function test_cleared_standard_returns_cleared_copy(): void
    {
        $invoice = $this->invoice($this->acme, ['status' => 'accepted', 'hash' => 'aGFzaA==', 'qr_code' => 'Q0xFQVJFRA==', 'signed_xml' => '<Invoice/>', 'cleared_xml' => '<Cleared/>']);

        $this->getJson("/api/v1/pipeline/status/{$invoice->id}", $this->headers)
            ->assertJsonPath('data.qr_code', 'Q0xFQVJFRA==')
            ->assertJsonPath('data.signed_xml', '<Cleared/>');
    }

    public function test_simplified_document_is_returned_at_issue(): void
    {
        $invoice = $this->invoice($this->acme, ['type' => 'simplified', 'status' => 'issued', 'hash' => 'aGFzaA==', 'qr_code' => 'UVI=', 'signed_xml' => '<Invoice/>']);

        $this->getJson("/api/v1/pipeline/status/{$invoice->id}", $this->headers)
            ->assertJsonPath('data.qr_code', 'UVI=')
            ->assertJsonPath('data.signed_xml', '<Invoice/>');
    }

    /** @param array<string, mixed> $state */
    private function invoice(Organization $organization, array $state = []): Invoice
    {
        $invoice = (new Invoice)->forceFill([
            'org_id' => $organization->id,
            'invoice_number' => 'INV-1',
            'type' => 'standard',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Buyer',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
            ...$state,
        ]);

        $invoice->save();

        return $invoice;
    }
}
