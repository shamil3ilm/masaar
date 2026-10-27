<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\PrintSamples;
use Tests\TestCase;

/**
 * The PDF and the receipt on the partner API, as the bearer-token API has
 * them: an ERP or a till's back office printing what it filed, with its own
 * key and no person's token.
 */
class PartnerPrintTest extends TestCase
{
    use PrintSamples;
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->sampleOrganization();
    }

    public function test_the_pdf_is_served(): void
    {
        $invoice = $this->sampleInvoice($this->organization, 'simplified', 3);

        $this->get('/api/v1/invoices/'.$invoice->id.'/pdf', $this->licence(['invoice.read']))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_the_receipt_is_printed(): void
    {
        $invoice = $this->sampleInvoice($this->organization, 'simplified', 3);
        $headers = $this->licence(['invoice.read']);

        $png = $this->get('/api/v1/invoices/'.$invoice->id.'/receipt-image?paper=80mm', $headers)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->getContent();
        $this->assertSame(576, imagesx(imagecreatefromstring((string) $png)));

        $bytes = (string) $this->get('/api/v1/invoices/'.$invoice->id.'/escpos', $headers)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/octet-stream')
            ->getContent();
        $this->assertStringStartsWith("\x1B\x40", $bytes);
    }

    public function test_another_organizations_is_not_found(): void
    {
        $theirs = $this->sampleInvoice($this->sampleOrganization(), 'simplified', 1);
        $headers = $this->licence(['invoice.read']);

        foreach (['pdf', 'receipt-image', 'escpos'] as $route) {
            $this->getJson('/api/v1/invoices/'.$theirs->id.'/'.$route, $headers)
                ->assertNotFound()
                ->assertJsonPath('error.code', 'NOT_FOUND');
        }
    }

    public function test_reading_needs_the_read_scope(): void
    {
        $invoice = $this->sampleInvoice($this->organization, 'simplified', 1);

        $this->getJson('/api/v1/invoices/'.$invoice->id.'/pdf', $this->licence(['compliance.status']))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'AUTH_SCOPE_DENIED');
    }

    /**
     * @param  list<string>  $scopes
     * @return array<string, string>
     */
    private function licence(array $scopes): array
    {
        $issued = License::createWithCredentials([
            'org_id' => $this->organization->id, 'organization_name' => 'Print', 'contact_email' => 'erp@example.test', 'tier' => 'enterprise',
        ]);
        License::withoutGlobalScopes()->whereKey($issued['license']->id)->update(['status' => 'active', 'scopes' => json_encode($scopes)]);

        return ['X-API-Key' => $issued['api_key'], 'X-API-Secret' => $issued['api_secret']];
    }
}
