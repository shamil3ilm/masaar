<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\UnitedArabEmirates;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Document\PeppolPreparer;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Document\PintUblBuilder;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceCharge;
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

    /**
     * A line's discount (IBG-27) and charge (IBG-28) before its item, in its
     * net (IBR-147-AE); document charges (IBG-21) at each category, the
     * exempt one naming its exemption (IBR-169-AE) and the out-of-scope one
     * no rate (ALIGNED-IBRP-O-07), a percentage with its base
     * (ALIGNED-IBRP-058); and the totals that add them (IBT-108, IBT-109).
     */
    public function test_charges_reach_the_einvoice(): void
    {
        $invoice = $this->uaeInvoice($this->uaeOrganization(), ['subtotal' => '950.00'], [
            $this->uaeLine(['net_amount' => 950, 'discount_amount' => 100, 'tax_amount' => 47.5, 'line_total' => 997.5]),
        ]);
        $invoice->forceFill(['status' => 'draft'])->save();
        $line = $invoice->lines->first();

        foreach ([
            [$line->id, 'IAA', 'Installation', '50.00', null, null, null, null],
            [null, 'DL', 'Delivery', '35.00', null, null, 'S', '5'],
            [null, 'FI', 'Bank fee', '15.00', null, null, 'E', '0', 'DL8.46.1'],
            [null, 'ABK', 'Government fee', '10.00', null, null, 'O', '0'],
            [null, 'ADR', 'Service charge', '100.00', '10', '1000.00', 'S', '5'],
        ] as $position => $c) {
            InvoiceCharge::query()->create([
                'invoice_id' => $invoice->id, 'invoice_line_id' => $c[0], 'position' => $position,
                'reason_code' => $c[1], 'reason' => $c[2], 'amount' => $c[3], 'net_amount' => $c[3],
                'percent' => $c[4], 'base_amount' => $c[5], 'tax_category' => $c[6], 'tax_rate' => $c[7], 'exempt_code' => $c[8] ?? null,
            ]);
        }

        $invoice->forceFill(['status' => 'issued'])->save();
        $xpath = $this->xpath(Invoice::withoutTenantScope(fn () => $invoice->fresh(['lines', 'org'])));
        $charge = fn (string $code, string $path): string => $xpath->evaluate("string(/*/cac:AllowanceCharge[cbc:AllowanceChargeReasonCode='{$code}']/{$path})");

        $this->assertSame(['false', '100.00'], [$xpath->evaluate('string(//cac:InvoiceLine/cac:AllowanceCharge[1]/cbc:ChargeIndicator)'), $xpath->evaluate('string(//cac:InvoiceLine/cac:AllowanceCharge[1]/cbc:Amount)')]);
        $this->assertSame('IAA', $xpath->evaluate('string(//cac:InvoiceLine/cac:AllowanceCharge[2]/cbc:AllowanceChargeReasonCode)'));
        $this->assertSame(1.0, $xpath->evaluate('count(//cac:InvoiceLine/cac:AllowanceCharge[2]/following-sibling::cac:Item)'));
        $this->assertSame(['950.00', '500.00'], [$xpath->evaluate('string(//cac:InvoiceLine/cbc:LineExtensionAmount)'), $xpath->evaluate('string(//cac:InvoiceLine/cac:Price/cbc:PriceAmount)')]);

        $this->assertSame(['DL8.46.1', '0'], [$charge('FI', 'cac:TaxCategory/cbc:TaxExemptionReasonCode'), $charge('FI', 'cac:TaxCategory/cbc:Percent')]);
        $this->assertSame(['O', ''], [$charge('ABK', 'cac:TaxCategory/cbc:ID'), $charge('ABK', 'cac:TaxCategory/cbc:Percent')]);
        $this->assertSame(['10', '100.00', '1000.00'], [$charge('ADR', 'cbc:MultiplierFactorNumeric'), $charge('ADR', 'cbc:Amount'), $charge('ADR', 'cbc:BaseAmount')]);
        $this->assertSame(0.0, $xpath->evaluate("count(/*/cac:AllowanceCharge[cbc:AllowanceChargeReasonCode='DL']/cbc:BaseAmount)"));

        $this->assertSame(['160.00', '1110.00'], [$xpath->evaluate('string(//cac:LegalMonetaryTotal/cbc:ChargeTotalAmount)'), $xpath->evaluate('string(//cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount)')]);
        $this->assertSame('1085.00', $xpath->evaluate("string(//cac:TaxSubtotal[cac:TaxCategory/cbc:ID='S']/cbc:TaxableAmount)"));
        $this->assertSame(['15.00', 'DL8.46.1'], [$xpath->evaluate("string(//cac:TaxSubtotal[cac:TaxCategory/cbc:ID='E']/cbc:TaxableAmount)"), $xpath->evaluate("string(//cac:TaxSubtotal[cac:TaxCategory/cbc:ID='E']/cac:TaxCategory/cbc:TaxExemptionReasonCode)")]);
        $this->assertSame('10.00', $xpath->evaluate("string(//cac:TaxSubtotal[cac:TaxCategory/cbc:ID='O']/cbc:TaxableAmount)"));
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
