<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Fatoora\Services\DocumentBuilder;
use App\Domains\Compliance\Fatoora\Services\InvoiceValidator;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Prices entered with VAT in them, from the API to the signed document.
 *
 * The customer's figures are the fixed ones: three at 10.00 is 30.00 on the
 * receipt and 30.00 in the document ZATCA receives, with the net backed out
 * of it. An organization sets which way it prices by default; an invoice can
 * say otherwise.
 */
class InclusivePricingTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private const CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private const CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    private Organization $acme;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::create([
            'name' => 'Acme Trading',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]);

        $user = User::factory()->create();
        $user->organizations()->attach($this->acme->id, ['role' => 'admin', 'status' => 'active']);
        $this->token = JWTAuth::claims(['org_id' => $this->acme->id, 'role' => 'admin'])->fromUser($user);
    }

    public function test_inclusive_prices_are_stored_net(): void
    {
        $invoice = $this->create(['prices_include_vat' => true])
            ->assertCreated()
            ->assertJsonPath('data.invoice.prices_include_vat', true)
            ->assertJsonPath('data.invoice.subtotal', '26.09')
            ->assertJsonPath('data.invoice.tax_amount', '3.91')
            ->assertJsonPath('data.invoice.total', '30.00')
            ->json('data.invoice');

        $line = $invoice['lines'][0];
        $this->assertSame('8.70', $line['unit_price']);
        $this->assertSame('26.09', $line['net_amount']);
        $this->assertSame('30.00', $line['line_total']);
    }

    public function test_the_organization_default_applies(): void
    {
        $this->withToken($this->token)
            ->putJson("/api/organizations/{$this->acme->id}", ['prices_include_vat' => true])
            ->assertOk()
            ->assertJsonPath('data.organization.prices_include_vat', true);

        $this->create()->assertJsonPath('data.invoice.total', '30.00');
        $this->create(['prices_include_vat' => false])->assertJsonPath('data.invoice.total', '34.50');
    }

    /**
     * What ZATCA receives: net amounts that reconcile, and a total that is
     * what the customer paid.
     */
    public function test_the_document_carries_the_customer_total(): void
    {
        $xpath = $this->document($this->create(['prices_include_vat' => true])->json('data.invoice.id'));

        $this->assertSame('30.00', $this->one($xpath, '/*/cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount'));
        $this->assertSame('26.09', $this->one($xpath, '/*/cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount'));
        $this->assertSame('3.91', $this->one($xpath, '/*/cac:TaxTotal/cac:TaxSubtotal/cbc:TaxAmount'));
        $this->assertSame('26.09', $this->one($xpath, '/*/cac:InvoiceLine/cbc:LineExtensionAmount'));
        $this->assertSame('30.00', $this->one($xpath, '/*/cac:InvoiceLine/cac:TaxTotal/cbc:RoundingAmount'));

        // BR-KSA-EN16931-11: three times the price is the line net.
        $price = (float) $this->one($xpath, '/*/cac:InvoiceLine/cac:Price/cbc:PriceAmount');
        $this->assertSame(26.09, round(3 * $price, 2));
    }

    /**
     * Where net and VAT, each rounded, cannot reach the gross, the document
     * still declares what the customer paid, and its categories still add up
     * to its VAT (BR-CO-14). The halalah is in the category's VAT, which ZATCA
     * reports under BR-S-09 as a warning.
     */
    public function test_an_unreachable_gross_is_still_exact(): void
    {
        $id = $this->create([
            'prices_include_vat' => true,
            'lines' => [['description' => 'Item', 'quantity' => 1, 'unit_price' => '10.00', 'tax_rate' => 15]],
        ])->json('data.invoice.id');

        $xpath = $this->document($id);

        $this->assertSame('10.00', $this->one($xpath, '/*/cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount'));
        $this->assertSame('1.30', $this->one($xpath, '/*/cac:TaxTotal/cbc:TaxAmount'));
        $this->assertSame('1.30', $this->one($xpath, '/*/cac:TaxTotal/cac:TaxSubtotal/cbc:TaxAmount'));
    }

    /**
     * The gate passes them, including a gross whose net and VAT, each
     * rounded, cannot reach it exactly.
     */
    public function test_inclusive_invoices_pass_validation(): void
    {
        // 10.00 alone is out of reach: a net of 8.70 and its VAT of 1.31 come
        // to 10.01. The VAT is then 1.30, what the gross leaves.
        foreach (['10.00', '30.00', '0.07', '19.99', '1234.56'] as $price) {
            $id = $this->create([
                'prices_include_vat' => true,
                'lines' => [['description' => 'Item', 'quantity' => 1, 'unit_price' => $price, 'tax_rate' => 15]],
            ])->assertCreated()->json('data.invoice.id');

            $invoice = Invoice::withoutTenantScope(fn () => Invoice::with('lines')->find($id));
            $result = app(InvoiceValidator::class)->validate($invoice, $this->acme);

            $this->assertSame([], $result['errors'], "gross {$price}");
            $this->assertSame($price, (string) $invoice->total);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function create(array $overrides = []): TestResponse
    {
        return $this->withToken($this->token)->postJson('/api/invoices', [
            'invoice_number' => 'INV-'.uniqid(),
            'type' => 'simplified',
            'issue_date' => now()->toDateString(),
            'buyer_name' => 'Walk-in customer',
            'lines' => [['description' => 'Widget', 'quantity' => 3, 'unit_price' => '10.00', 'tax_rate' => 15]],
            ...$overrides,
        ]);
    }

    private function document(string $id): DOMXPath
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::with('lines')->findOrFail($id));
        $credentials = $this->selfSignedCredentials();

        $xml = app(DocumentBuilder::class)->generateComplianceData(
            invoice: $invoice,
            organization: $this->acme,
            previousInvoiceHash: null,
            privateKey: $credentials['privateKey'],
            certificate: $credentials['certificate'],
        )['xml'];

        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = false;
        $dom->loadXML($xml);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('cbc', self::CBC);
        $xpath->registerNamespace('cac', self::CAC);

        return $xpath;
    }

    private function one(DOMXPath $xpath, string $query): ?string
    {
        return $xpath->query($query)->item(0)?->textContent;
    }
}
