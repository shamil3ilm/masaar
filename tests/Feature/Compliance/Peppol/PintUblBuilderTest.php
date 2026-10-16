<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\Peppol;

use App\Domains\Compliance\Peppol\Services\PeppolPreparer;
use App\Domains\Compliance\Peppol\Services\PintUblBuilder;
use App\Domains\Invoice\Models\Invoice;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The XML itself: which root, which elements, and what is left out.
 * PintConformanceTest puts the same documents in front of the official
 * rules; these hold the shapes that matter without needing Java.
 */
class PintUblBuilderTest extends TestCase
{
    use PintInvoices;
    use RefreshDatabase;

    public function test_invoice_carries_pint_ae_header(): void
    {
        $xpath = $this->xpath($this->uaeInvoice($this->uaeOrganization()));

        $this->assertSame('Invoice', $xpath->document->documentElement->localName);
        $this->assertSame('urn:peppol:pint:billing-1@ae-1', $xpath->evaluate('string(/*/cbc:CustomizationID)'));
        $this->assertSame('00000000', $xpath->evaluate('string(/*/cbc:ProfileExecutionID)'));
        $this->assertSame('380', $xpath->evaluate('string(/*/cbc:InvoiceTypeCode)'));
        $this->assertSame('0235', $xpath->evaluate('string(//cac:AccountingSupplierParty//cbc:EndpointID/@schemeID)'));
        $this->assertSame('1050.00', $xpath->evaluate('string(//cac:ItemPriceExtension/cbc:Amount)'));
    }

    public function test_credit_note_uses_its_own_root(): void
    {
        $xpath = $this->xpath($this->uaeInvoice($this->uaeOrganization(), ['document_type' => 'credit_note', 'billing_ref' => 'AE-1'], null, ['credit_reason' => 'DL8.61.1.A']));

        $this->assertSame('CreditNote', $xpath->document->documentElement->localName);
        $this->assertSame('381', $xpath->evaluate('string(/*/cbc:CreditNoteTypeCode)'));
        $this->assertSame('DL8.61.1.A', $xpath->evaluate('string(/*/cac:DiscrepancyResponse/cbc:ResponseCode)'));
        $this->assertSame('AE-1', $xpath->evaluate('string(/*/cac:BillingReference/cac:InvoiceDocumentReference/cbc:ID)'));
        $this->assertSame(1.0, $xpath->evaluate('count(//cac:CreditNoteLine/cbc:CreditedQuantity)'));
        $this->assertSame(0.0, $xpath->evaluate('count(/*/cbc:DueDate | /*/cac:PaymentMeans)'));
    }

    public function test_names_are_escaped_not_mangled(): void
    {
        $xpath = $this->xpath($this->uaeInvoice($this->uaeOrganization(), ['buyer_name' => 'Noor & Sons <Trading>']));

        $this->assertSame('Noor & Sons <Trading>', $xpath->evaluate('string(//cac:AccountingCustomerParty//cbc:RegistrationName)'));
    }

    /**
     * IBR-163-AE and ALIGNED-IBRP-E-05: no VAT amount and no rate on an
     * exempt line, and the exemption named instead.
     */
    public function test_exempt_line_has_no_vat(): void
    {
        $xpath = $this->xpath($this->uaeInvoice($this->uaeOrganization(), [], [
            $this->uaeLine(['tax_category' => 'E', 'tax_rate' => 0, 'tax_amount' => 0, 'exempt_code' => 'DL8.46.3', 'exempt_reason' => 'Bare land']),
        ]));

        $this->assertSame(0.0, $xpath->evaluate('count(//cac:ItemPriceExtension/cac:TaxTotal)'));
        $this->assertSame(0.0, $xpath->evaluate('count(//cac:ClassifiedTaxCategory/cbc:Percent)'));
        $this->assertSame('DL8.46.3', $xpath->evaluate('string(//cac:ClassifiedTaxCategory/cbc:TaxExemptionReasonCode)'));
    }

    /** IBR-079: an empty element is an error, so nothing absent is written. */
    public function test_no_element_is_empty(): void
    {
        $xpath = $this->xpath($this->uaeInvoice($this->uaeOrganization(), ['buyer_vat_number' => null, 'due_date' => null]));

        $this->assertSame(0.0, $xpath->evaluate('count(//*[not(*) and not(normalize-space())])'));
    }

    private function xpath(Invoice $invoice): DOMXPath
    {
        $xml = app(PintUblBuilder::class)->build(app(PeppolPreparer::class)->document($invoice));
        $document = new DOMDocument;
        $document->loadXML($xml);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

        return $xpath;
    }
}
