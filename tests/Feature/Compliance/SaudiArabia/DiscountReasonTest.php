<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\SaudiArabia;

use App\Domains\Compliance\Countries\SaudiArabia\Document\XmlBuilder;
use App\Domains\Compliance\Countries\SaudiArabia\DTOs\AddressData;
use App\Domains\Compliance\Countries\SaudiArabia\DTOs\InvoiceXmlData;
use DOMDocument;
use DOMXPath;
use Tests\TestCase;

/**
 * BT-97, the reason for a document-level discount. When the invoice gives
 * none, the default is written in both languages, as the note reasons are,
 * so the buyer and the authority read the same thing.
 */
class DiscountReasonTest extends TestCase
{
    public function test_default_reason_is_bilingual(): void
    {
        $this->assertSame(['Discount / خصم'], $this->reasons(null));
    }

    public function test_given_reason_is_kept(): void
    {
        $this->assertSame(['Loyalty'], $this->reasons('Loyalty'));
    }

    /**
     * @return list<string>
     */
    private function reasons(?string $reason): array
    {
        $xml = app(XmlBuilder::class)->build(new InvoiceXmlData(
            uuid: '3cf5ee18-ee25-44ea-a444-2c37ba7f28be', invoiceNumber: 'D-1', icv: 1,
            issueDate: '2026-10-20', issueTime: '10:00:00', invoiceTypeCode: '388', invoiceSubtype: '02', currency: 'SAR',
            sellerName: 'Acme Trading', sellerVatNumber: '300000000000003',
            sellerAddress: new AddressData(street: 'King Fahd Road', buildingNumber: '1234', district: 'Al Olaya', city: 'Riyadh', postalCode: '12345'),
            buyerName: 'Cash Customer', subtotal: 100.0, taxAmount: 13.5, total: 103.5,
            lines: [['description' => 'Widget', 'quantity' => 1.0, 'unitPrice' => 100.0, 'taxRate' => 15.0, 'taxAmount' => 15.0, 'lineTotal' => 100.0, 'taxCategory' => 'S', 'unitCode' => 'PCE']],
            sellerCrNumber: '1010101010',
            discount: 10.0,
            discountReason: $reason,
        ));

        $dom = new DOMDocument;
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

        $reasons = [];

        foreach ($xpath->query('/*/cac:AllowanceCharge/cbc:AllowanceChargeReason') as $node) {
            $reasons[] = $node->textContent;
        }

        return $reasons;
    }
}
