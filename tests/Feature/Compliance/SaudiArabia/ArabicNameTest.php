<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Document\DocumentBuilder;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\InvoiceValidator;
use App\Domains\Compliance\Countries\SaudiArabia\Support\TextNormalizer;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceLine;
use App\Domains\Organization\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\SigningCredentials;
use Tests\TestCase;

/**
 * A Saudi tax invoice is issued in Arabic, and the QR says so first.
 *
 * Arabic is required on a tax invoice and on a simplified one; another
 * language may sit alongside it. The requirement is about the fields a person
 * reads, and the most widely read of them is not on the page at all - it is
 * tag 1 of the QR, which is the seller name, and which anyone with a phone can
 * scan off a receipt.
 *
 * Until this existed the only way to get Arabic onto a document was to put it
 * in organizations.name, the single Latin-intended column, and hope. There was
 * no Arabic buyer name and no Arabic line description anywhere in the schema.
 * An organization registered under its English name produced a QR and a signed
 * document with no Arabic in them.
 *
 * documentName() is the one expression that decides, so the XML and the QR
 * cannot disagree - SellerNameBytesTest holds that they are the same bytes,
 * and it would catch them drifting.
 */
class ArabicNameTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    private const ARABIC = 'شركة الأمل للتجارة';

    private const LATIN = 'Al Amal Trading Company';

    public function test_the_qr_carries_the_arabic_name(): void
    {
        $tags = $this->decodeTlv(base64_decode($this->sign(self::ARABIC)['qr_code']));

        // Compared against the normalised form rather than the raw one. The
        // platform folds Alef and Yeh variants, drops tashkeel and collapses
        // whitespace before the name is hashed, so the QR carries
        // "شركه الامل للتجاره" and not what was typed. That is deliberate and
        // predates this - SellerNameBytesTest explains it, and it is what
        // keeps tag 1 and the XML byte-identical. The assertion here is about
        // which column the name came from, so it normalises the expectation
        // the same way rather than hardcoding the result.
        $this->assertSame(TextNormalizer::normalizeName(self::ARABIC), $tags[1],
            'Tag 1 of the QR is the seller name, and it did not come from the Arabic '
            .'column. That is the field anybody scanning the receipt reads.');

        $this->assertNotSame(self::LATIN, $tags[1], 'The QR fell back to the Latin name.');
    }

    /**
     * And without one, the Latin name still produces a document.
     *
     * The column is new and nullable, nothing has been backfilled, and an
     * invoice written before it existed still has to build.
     */
    public function test_the_latin_name_is_the_fallback(): void
    {
        $tags = $this->decodeTlv(base64_decode($this->sign(null)['qr_code']));

        $this->assertSame(self::LATIN, $tags[1]);
    }

    /**
     * An Arabic name of spaces is not an Arabic name.
     */
    public function test_a_blank_arabic_name_falls_back(): void
    {
        $tags = $this->decodeTlv(base64_decode($this->sign('   ')['qr_code']));

        $this->assertSame(self::LATIN, $tags[1]);
    }

    /**
     * A Latin name is not flagged, because the authority approves it.
     *
     * This test asserted the opposite until the primary source was read.
     * Article 66(2) requires invoices to be issued in Arabic and ZATCA's
     * Guideline for Tax Invoicing and Records (v3, May 2026) section 4.2.1
     * says every field must be completed in Arabic - and then exempts exactly
     * the fields stored here:
     *
     *   "Proper names include the names of persons and places, the registered
     *   branch, and product names ... ZATCA approves the use of Latin
     *   characters for writing such names as part of the Arabic language
     *   requirements in the tax invoice."
     *
     * Warning on a Latin seller name would therefore tell every organization
     * in the Kingdom it was non-compliant for something the authority permits
     * in writing. The requirement bites on the rendition instead - the
     * document title and the field captions - which is a template concern.
     */
    public function test_a_latin_name_is_not_flagged(): void
    {
        $organization = $this->organization(null);
        $invoice = $this->invoice($organization, buyerArabic: null, lineArabic: null);

        $warnings = implode(' | ', app(InvoiceValidator::class)->validate($invoice, $organization)['warnings']);

        $this->assertStringNotContainsString('Arabic', $warnings,
            'A Latin proper name was reported as a compliance problem. ZATCA approves '
            .'Latin characters for names of persons, places, branches and products.');
    }

    private function organization(?string $arabic): Organization
    {
        return Organization::create([
            'name' => self::LATIN,
            'name_ar' => $arabic,
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]);
    }

    private function invoice(Organization $organization, ?string $buyerArabic, ?string $lineArabic): Invoice
    {
        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $organization->id,
            'invoice_number' => 'INV-'.uniqid(),
            'type' => 'standard',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Buyer Co',
            'buyer_name_ar' => $buyerArabic,
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => 'Consulting',
            'description_ar' => $lineArabic,
            'quantity' => '1',
            'unit_price' => '100.00',
            'tax_rate' => '15',
            'tax_amount' => '15.00',
            'line_total' => '115.00',
        ]);

        return $invoice->fresh(['lines']);
    }

    /**
     * @return array{xml: string, hash: string, qr_code: string, signed_xml: ?string}
     */
    private function sign(?string $arabic): array
    {
        $organization = $this->organization($arabic);
        $invoice = $this->invoice($organization, buyerArabic: null, lineArabic: null);

        $credentials = $this->selfSignedCredentials();

        return app(DocumentBuilder::class)->generateComplianceData(
            invoice: $invoice,
            organization: $organization,
            previousInvoiceHash: null,
            privateKey: $credentials['privateKey'],
            certificate: $credentials['certificate'],
        );
    }

    /**
     * @return array<int, string>
     */
    private function decodeTlv(string $payload): array
    {
        $tags = [];
        $offset = 0;

        while ($offset < strlen($payload) - 1) {
            $tag = ord($payload[$offset]);
            $length = ord($payload[$offset + 1]);
            $tags[$tag] = substr($payload, $offset + 2, $length);
            $offset += 2 + $length;
        }

        return $tags;
    }
}
