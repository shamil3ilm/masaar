<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domains\Compliance\Fatoora\Services\DocumentBuilder;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * Saudi VAT is reported in riyals whatever currency the invoice is written in.
 *
 * An invoice may be denominated in dollars, and the tax on it is still a Saudi
 * tax owed in riyals — so the document states both: the amount in its own
 * currency, and the same tax converted, with TaxCurrencyCode saying SAR.
 *
 * The builder had the machinery for this and no way to reach it. Whether a
 * document counted as multi-currency was decided by an originalCurrency field
 * that the invoice does not have and nothing ever set, so the answer was always
 * no: a dollar invoice declared its VAT in dollars under a TaxCurrencyCode of
 * USD, and the authority would read 15 where 56.25 was owed.
 */
class ForeignCurrencyTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private const CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private Organization $organization;

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
    }

    public function test_tax_is_reported_in_riyals(): void
    {
        $xpath = $this->document('USD', '3.750000');

        $this->assertSame('USD', $this->one($xpath, '/*/cbc:DocumentCurrencyCode'));
        $this->assertSame(
            'SAR',
            $this->one($xpath, '/*/cbc:TaxCurrencyCode'),
            'A foreign-currency invoice reports its Saudi VAT in its own currency.'
        );
    }

    /**
     * 15 dollars of VAT at 3.75 is 56.25 riyals, and the document has to say
     * so — the figure the authority reads is the converted one.
     */
    public function test_the_converted_amount_is_stated(): void
    {
        $xpath = $this->document('USD', '3.750000');

        $sar = [];

        foreach ($xpath->query('//cbc:TaxAmount[@currencyID="SAR"]') as $node) {
            $sar[] = (float) $node->textContent;
        }

        $this->assertContains(56.25, $sar, 'The tax was never converted to riyals.');
    }

    /**
     * A riyal invoice has nothing to convert, and must not gain a second
     * currency for the sake of it.
     */
    public function test_a_riyal_invoice_is_unchanged(): void
    {
        $xpath = $this->document('SAR', null);

        $this->assertSame('SAR', $this->one($xpath, '/*/cbc:DocumentCurrencyCode'));
        $this->assertSame('SAR', $this->one($xpath, '/*/cbc:TaxCurrencyCode'));
    }

    /**
     * Without a rate there is nothing to convert with, and inventing one would
     * be worse than declaring the document currency.
     */
    public function test_no_rate_means_no_conversion(): void
    {
        $xpath = $this->document('USD', null);

        $this->assertSame('USD', $this->one($xpath, '/*/cbc:TaxCurrencyCode'));
    }

    private function document(string $currency, ?string $rate): DOMXPath
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $this->organization->id,
            'invoice_number' => 'INV-'.uniqid(),
            'type' => 'standard',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => $currency,
            'exchange_rate' => $rate,
            'buyer_name' => 'Buyer Co',
            'buyer_vat_number' => '311111111111113',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));

        $invoice->lines()->create([
            'description' => 'Item',
            'quantity' => '1',
            'unit_code' => 'PCE',
            'unit_price' => '100.00',
            'tax_rate' => '15',
            'tax_amount' => '15.00',
            'tax_category' => 'S',
            'line_total' => '115.00',
        ]);

        $credentials = $this->selfSignedCredentials();

        $xml = app(DocumentBuilder::class)->generateComplianceData(
            invoice: $invoice->fresh('lines'),
            organization: $this->organization,
            previousInvoiceHash: null,
            privateKey: $credentials['privateKey'],
            certificate: $credentials['certificate'],
        )['xml'];

        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = false;
        $dom->loadXML($xml);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('cbc', self::CBC);

        return $xpath;
    }

    private function one(DOMXPath $xpath, string $query): ?string
    {
        return $xpath->query($query)->item(0)?->textContent;
    }
}
