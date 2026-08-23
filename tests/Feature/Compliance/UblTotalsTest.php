<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Fatoora\Services\DocumentBuilder;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * The arithmetic ZATCA checks before it reads anything else.
 *
 * A UBL invoice states the same money several times over, and the statements
 * have to agree: line amounts sum to the document's line extension, the
 * taxable amount is that less any allowance, tax is that base times the rate,
 * and the inclusive total is the two added. An invoice that disagrees with
 * itself is rejected on arithmetic, whatever else is right about it.
 *
 * These are BR-CO-13 and BR-CO-15 in EN 16931 terms, and they can be asserted
 * without ZATCA's own fixtures because the document is checked against itself
 * — which is the part of W-5.1 that does not need their sample invoices.
 */
class UblTotalsTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private const CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private const CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    private Organization $organization;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Acme Trading',
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'district' => 'Al Olaya',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]);

        $user = User::factory()->create(['email' => 'biller@masaar.test']);
        $user->organizations()->attach($this->organization->id, [
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->token = $this->postJson('/api/auth/login', [
            'email' => 'biller@masaar.test',
            'password' => 'password',
        ])->json('data.token.access_token');
    }

    public function test_a_plain_invoice_adds_up(): void
    {
        $this->assertConsistent($this->build([
            ['qty' => 1, 'price' => '1000.00', 'rate' => '15', 'category' => 'S'],
        ]));
    }

    public function test_several_lines_add_up(): void
    {
        $this->assertConsistent($this->build([
            ['qty' => 2, 'price' => '250.00', 'rate' => '15', 'category' => 'S'],
            ['qty' => 3, 'price' => '99.99', 'rate' => '15', 'category' => 'S'],
        ]));
    }

    /**
     * A zero-rated line contributes to the taxable amount and nothing to the
     * tax, so the two totals stop moving together — which is where a document
     * that only ever carried one rate starts to disagree with itself.
     */
    public function test_mixed_categories_add_up(): void
    {
        $this->assertConsistent($this->build([
            ['qty' => 1, 'price' => '1000.00', 'rate' => '15', 'category' => 'S'],
            ['qty' => 1, 'price' => '500.00', 'rate' => '0', 'category' => 'Z'],
        ]));
    }

    /**
     * A document-level allowance reduces what is taxable. Charging VAT on the
     * amount before the discount overstates the tax the taxpayer owes, and
     * leaves TaxExclusiveAmount describing money nobody is paying.
     */
    public function test_a_discount_reduces_the_tax(): void
    {
        $xml = $this->build([
            ['qty' => 1, 'price' => '1000.00', 'rate' => '15', 'category' => 'S'],
        ], discount: '100.00');

        $this->assertConsistent($xml);

        $xpath = $this->xpath($xml);

        $this->assertEqualsWithDelta(
            135.00,
            (float) $this->one($xpath, '/*/cac:TaxTotal/cbc:TaxAmount'),
            0.01,
            'VAT was charged on the amount before the discount.'
        );
    }

    /**
     * With more than one category the allowance has to be shared out, or one
     * category absorbs all of it and both bases are wrong.
     */
    public function test_a_discount_is_shared_across_categories(): void
    {
        $this->assertConsistent($this->build([
            ['qty' => 1, 'price' => '1000.00', 'rate' => '15', 'category' => 'S'],
            ['qty' => 1, 'price' => '1000.00', 'rate' => '0', 'category' => 'Z'],
        ], discount: '200.00'));
    }

    /**
     * Every identity, checked against the document itself.
     */
    private function assertConsistent(string $xml): void
    {
        $xpath = $this->xpath($xml);

        $lineNets = [];

        foreach ($xpath->query('//cac:InvoiceLine') as $line) {
            $quantity = (float) $this->one($xpath, 'cbc:InvoicedQuantity', $line);
            $price = (float) $this->one($xpath, 'cac:Price/cbc:PriceAmount', $line);
            $net = (float) $this->one($xpath, 'cbc:LineExtensionAmount', $line);

            $this->assertEqualsWithDelta(
                round($quantity * $price, 2),
                $net,
                0.01,
                'A line amount is not quantity times price — it carries the tax as well.'
            );

            $lineNets[] = $net;
        }

        $documentNet = (float) $this->one($xpath, '/*/cac:LegalMonetaryTotal/cbc:LineExtensionAmount');
        $allowance = (float) ($this->one($xpath, '/*/cac:LegalMonetaryTotal/cbc:AllowanceTotalAmount') ?? '0');
        $taxExclusive = (float) $this->one($xpath, '/*/cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount');
        $taxInclusive = (float) $this->one($xpath, '/*/cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount');
        $taxTotal = (float) $this->one($xpath, '/*/cac:TaxTotal/cbc:TaxAmount');

        $this->assertEqualsWithDelta(
            array_sum($lineNets), $documentNet, 0.01,
            'The document line extension is not the sum of its lines.'
        );

        $this->assertEqualsWithDelta(
            $documentNet - $allowance, $taxExclusive, 0.01,
            'TaxExclusiveAmount is not the line extension less the allowance (BR-CO-13).'
        );

        $this->assertEqualsWithDelta(
            $taxExclusive + $taxTotal, $taxInclusive, 0.01,
            'TaxInclusiveAmount is not the exclusive amount plus the tax (BR-CO-15).'
        );

        $bases = 0.0;
        $taxes = 0.0;

        foreach ($xpath->query('/*/cac:TaxTotal/cac:TaxSubtotal') as $subtotal) {
            $base = (float) $this->one($xpath, 'cbc:TaxableAmount', $subtotal);
            $tax = (float) $this->one($xpath, 'cbc:TaxAmount', $subtotal);
            $percent = (float) $this->one($xpath, 'cac:TaxCategory/cbc:Percent', $subtotal);

            $this->assertEqualsWithDelta(
                round($base * $percent / 100, 2), $tax, 0.02,
                "A tax subtotal declares {$tax} on a base of {$base} at {$percent}%."
            );

            $bases += $base;
            $taxes += $tax;
        }

        $this->assertEqualsWithDelta(
            $taxExclusive, $bases, 0.01,
            'The taxable amounts do not sum to the tax-exclusive total.'
        );

        $this->assertEqualsWithDelta($taxTotal, $taxes, 0.01);
    }

    /**
     * Build the invoice through the API, then generate its document.
     *
     * Through the API on purpose: the totals a document declares come from the
     * controller, and asserting the identities against numbers the test worked
     * out for itself would only prove the test agrees with the test.
     *
     * @param  list<array{qty: int, price: string, rate: string, category: string}>  $lines
     */
    private function build(array $lines, string $discount = '0.00'): string
    {
        $payload = [
            'invoice_number' => 'INV-'.uniqid(),
            'type' => 'simplified',
            'issue_date' => now()->toDateString(),
            'buyer_name' => 'Buyer Co',
            'discount_amount' => $discount,
            'lines' => array_map(static fn (array $line): array => array_filter([
                'description' => 'Item',
                'quantity' => $line['qty'],
                'unit_price' => $line['price'],
                'tax_rate' => $line['rate'],
                'tax_category' => $line['category'],
                'exempt_code' => $line['category'] === 'S' ? null : 'VATEX-SA-HEA',
                'exempt_reason' => $line['category'] === 'S' ? null : 'Zero-rated supply',
            ], static fn ($value): bool => $value !== null), $lines),
        ];

        $id = $this->withToken($this->token)
            ->postJson('/api/invoices', $payload)
            ->assertSuccessful()
            ->json('data.invoice.id');

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::with('lines')->findOrFail($id));

        $credentials = $this->selfSignedCredentials();

        return app(DocumentBuilder::class)->generateComplianceData(
            invoice: $invoice,
            organization: $this->organization,
            previousInvoiceHash: null,
            privateKey: $credentials['privateKey'],
            certificate: $credentials['certificate'],
        )['xml'];
    }

    private function xpath(string $xml): DOMXPath
    {
        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = false;
        $dom->loadXML($xml);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('cbc', self::CBC);
        $xpath->registerNamespace('cac', self::CAC);

        return $xpath;
    }

    private function one(DOMXPath $xpath, string $query, ?\DOMNode $context = null): ?string
    {
        $node = $xpath->query($query, $context)->item(0);

        return $node?->textContent;
    }
}
