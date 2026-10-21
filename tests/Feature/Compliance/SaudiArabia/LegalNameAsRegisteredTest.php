<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Document\DocumentBuilder;
use App\Domains\Compliance\Countries\SaudiArabia\Support\TextNormalizer;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * A legal name goes into the document as registered.
 *
 * The seller's and buyer's names were folded before they were signed - ة to
 * ه, ى to ي, أ/إ/آ to ا, tashkeel stripped, "Al-" rewritten - so the signed
 * XML and QR tag 1 named "شركه ... التجاريه" while the printed invoice and
 * the commercial registration said "شركة ... التجارية". Only what makes the
 * bytes safe is done now: valid UTF-8, NFC, control and bidi formatting
 * characters removed, whitespace collapsed and trimmed.
 */
class LegalNameAsRegisteredTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    public function test_letters_are_kept_as_registered(): void
    {
        $raw = "  \u{202E}شَرِكة  الأمل والإيمان وآفاق  لِلتجارة على  Al-Noor  ";

        $this->assertSame('شَرِكة الأمل والإيمان وآفاق لِلتجارة على Al-Noor', TextNormalizer::legalName($raw));
    }

    public function test_decomposed_text_is_composed(): void
    {
        // Alef followed by a combining hamza above (U+0654) is NFC أ (U+0623).
        $this->assertSame('أمل', TextNormalizer::legalName("\u{0627}\u{0654}مل"));
    }

    public function test_the_document_and_qr_keep_it(): void
    {
        $name = 'شركة نجد ووك التجارية';
        $organization = Organization::create([
            'name' => 'Najd Walk', 'name_ar' => $name, 'country' => 'SA', 'vat_number' => '399999999900003', 'cr_number' => '1010101010',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'district' => 'Al Olaya', 'city' => 'Riyadh', 'postal_code' => '12345',
        ]);
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $organization->id, 'invoice_number' => 'NAME-1', 'type' => 'simplified', 'document_type' => 'invoice', 'status' => 'draft',
            'issue_date' => now()->toDateString(), 'supply_date' => now()->toDateString(), 'currency' => 'SAR',
            'subtotal' => '100.00', 'tax_amount' => '15.00', 'total' => '115.00',
        ]));
        $invoice->lines()->create(['description' => 'Widget', 'quantity' => '1.000', 'unit_price' => '100.00', 'tax_rate' => '15.00', 'tax_amount' => '15.00', 'line_total' => '115.00']);
        $keys = $this->selfSignedCredentials();

        $result = app(DocumentBuilder::class)->generateComplianceData($invoice->fresh(['lines']), $organization, null, $keys['privateKey'], $keys['certificate']);

        $dom = new DOMDocument;
        $dom->loadXML((string) $result['signed_xml']);
        $path = new DOMXPath($dom);
        $path->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $path->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

        $this->assertSame($name, $path->evaluate('string(//cac:AccountingSupplierParty//cbc:RegistrationName)'));
        $this->assertSame($name, $this->tag1(base64_decode($result['qr_code'])));
    }

    private function tag1(string $tlv): string
    {
        return substr($tlv, 2, ord($tlv[1]));
    }
}
