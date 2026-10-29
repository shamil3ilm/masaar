<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Licensing\Models\License;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * A standard invoice may be shared only once ZATCA has cleared it (E-Invoicing
 * Detailed Guidelines, clearance). Before then the invoice API answers its QR
 * and signed XML as null, on every read: one invoice, the list and the sync
 * walk, on the tenant API and the partner API alike, as the pipeline does.
 */
class WithheldInvoiceApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private string $token;

    private int $number = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create(['name' => 'Acme', 'country' => 'SA']);

        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);

        $this->token = JWTAuth::claims(['org_id' => $this->acme->id, 'role' => 'admin'])->fromUser($user);
    }

    public function test_show_withholds_uncleared_standard_invoice(): void
    {
        $invoice = $this->issued();

        $this->tenant()->getJson("/api/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('data.invoice.id', $invoice->id)
            ->assertJsonPath('data.invoice.qr_code', null)
            ->assertJsonPath('data.invoice.signed_xml', null);
    }

    public function test_list_and_sync_withhold_it(): void
    {
        $this->issued();

        $this->tenant()->getJson('/api/invoices')
            ->assertOk()
            ->assertJsonPath('data.0.qr_code', null)
            ->assertJsonPath('data.0.signed_xml', null);

        $this->tenant()->getJson('/api/invoices?cursor=')
            ->assertOk()
            ->assertJsonPath('data.0.qr_code', null)
            ->assertJsonPath('data.0.signed_xml', null);
    }

    public function test_partner_api_withholds_it_too(): void
    {
        $invoice = $this->issued();
        $issued = License::createWithCredentials([
            'org_id' => $this->acme->id,
            'organization_name' => 'Acme',
            'contact_email' => 'acme@example.test',
            'tier' => 'starter',
            'scopes' => ['invoice.read'],
        ]);
        $headers = ['X-API-Key' => $issued['api_key'], 'X-API-Secret' => $issued['api_secret']];

        $this->getJson("/api/v1/invoices/{$invoice->id}", $headers)
            ->assertOk()
            ->assertJsonPath('data.invoice.qr_code', null)
            ->assertJsonPath('data.invoice.signed_xml', null);

        $this->getJson('/api/v1/invoices', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.qr_code', null);
    }

    public function test_compliance_status_withholds_the_qr(): void
    {
        $invoice = $this->issued();

        $this->tenant()->getJson("/api/compliance/sa/status/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('data.qr_code', null);
    }

    public function test_cleared_and_simplified_invoices_are_shown(): void
    {
        $cleared = $this->issued(['cleared_xml' => '<Invoice cleared="1"/>']);
        $simplified = $this->issued(['type' => 'simplified']);

        $this->tenant()->getJson("/api/invoices/{$cleared->id}")
            ->assertJsonPath('data.invoice.qr_code', 'UVI=')
            ->assertJsonPath('data.invoice.signed_xml', '<Invoice/>');

        $this->tenant()->getJson("/api/invoices/{$simplified->id}")
            ->assertJsonPath('data.invoice.qr_code', 'UVI=')
            ->assertJsonPath('data.invoice.signed_xml', '<Invoice/>');
    }

    private function tenant(): self
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function issued(array $attributes = []): Invoice
    {
        $this->number++;

        $invoice = (new Invoice)->forceFill([
            'org_id' => $this->acme->id,
            'invoice_number' => 'INV-'.$this->number,
            'type' => 'standard',
            'status' => 'issued',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Buyer',
            'buyer_name_ar' => 'المشتري',
            'hash' => 'aGFzaA==',
            'qr_code' => 'UVI=',
            'signed_xml' => '<Invoice/>',
            ...$attributes,
        ]);

        $invoice->save();

        return $invoice;
    }
}
