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
 * The seller's name appears twice in a cleared invoice — as the XML's
 * RegistrationName and as TLV tag 1 of the QR — and ZATCA compares them.
 *
 * They must therefore be the same bytes. Arabic is where that stops being
 * automatic: the platform strips diacritics, folds Alef and Yeh variants and
 * collapses whitespace before hashing, so a name normalised on one side and
 * copied raw on the other differs invisibly. Both sides normalise today and
 * nothing held them together.
 */
class SellerNameBytesTest extends TestCase
{
    use RefreshDatabase;
    use SigningCredentials;

    /**
     * A name carrying the things normalisation removes: a tatweel, tashkeel,
     * an Alef with hamza, and doubled spacing.
     */
    private const RAW_NAME = 'شَركة  الأمــل للتجارة';

    public function test_qr_matches_the_xml_seller(): void
    {
        $result = $this->sign();

        $tags = $this->decodeTlv(base64_decode($result['qr_code']));

        $this->assertSame(
            $this->registrationName($result['signed_xml']),
            $tags[1],
            'The QR and the XML name the seller differently.'
        );
    }

    /**
     * And that the name in the document is the normalised one, not the raw
     * value — otherwise the test above passes with both sides equally wrong.
     *
     * Only what TextNormalizer undertakes to do is asserted: tashkeel removed,
     * whitespace collapsed. It leaves the tatweel (U+0640) in place, and
     * whether ZATCA expects that elongation stripped before hashing is a
     * question their conformance fixtures settle rather than one to guess at
     * — see W-5.1.
     */
    public function test_the_seller_name_is_normalised(): void
    {
        $name = $this->registrationName($this->sign()['signed_xml']);

        $this->assertNotSame(self::RAW_NAME, $name, 'The name was not normalised at all.');
        $this->assertStringNotContainsString("\u{064E}", $name, 'The fatha survived.');
        $this->assertStringNotContainsString('  ', $name, 'Doubled spacing survived.');
    }

    private function registrationName(string $signedXml): string
    {
        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = false;
        $dom->loadXML($signedXml);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace(
            'cac',
            'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2'
        );
        $xpath->registerNamespace(
            'cbc',
            'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2'
        );

        $node = $xpath
            ->query('//cac:AccountingSupplierParty//cac:PartyLegalEntity/cbc:RegistrationName')
            ->item(0);

        $this->assertNotNull($node, 'The document has no supplier RegistrationName.');

        return $node->textContent;
    }

    /**
     * @return array{xml: string, hash: string, qr_code: string, signed_xml: ?string}
     */
    private function sign(): array
    {
        $organization = Organization::create([
            'name' => self::RAW_NAME,
            'country' => 'SA',
            'vat_number' => '300000000000003',
            'street' => 'King Fahd Road',
            'building_number' => '1234',
            'city' => 'Riyadh',
            'postal_code' => '12345',
        ]);

        $invoice = Invoice::withoutTenantScope(fn () => Invoice::create([
            'org_id' => $organization->id,
            'invoice_number' => 'INV-'.uniqid(),
            'type' => 'standard',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'currency' => 'SAR',
            'buyer_name' => 'Buyer Co',
            'subtotal' => '100.00',
            'tax_amount' => '15.00',
            'total' => '115.00',
        ]));

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
