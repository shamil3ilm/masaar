<?php

declare(strict_types=1);

namespace App\Domains\Compliance\FTA\Services;

use App\Domains\Compliance\FTA\DTOs\FtaInvoiceData;
use DOMDocument;
use DOMElement;

/**
 * Builds Peppol PINT AE (UBL 2.1) XML for UAE FTA e-invoicing.
 *
 * Specification: Peppol PINT AE — UAE national Peppol profile
 * CustomizationID: urn:peppol:pint:billing-1@ae-1
 * ProfileID: urn:peppol:bis:billing
 *
 * Reference: UAE Electronic Invoicing Guidelines v1.0 (Feb 2026)
 * Authority: UAE Federal Tax Authority (FTA)
 * Mandate: B2B/B2G only — B2C excluded until further notice
 */
class FtaXmlBuilder
{
    private const UBL_NS  = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
    private const CAC_NS  = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    private const CBC_NS  = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private const CUSTOMIZATION = 'urn:peppol:pint:billing-1@ae-1';
    private const PROFILE_ID    = 'urn:peppol:bis:billing';

    private DOMDocument $dom;

    public function build(FtaInvoiceData $data): string
    {
        $this->dom = new DOMDocument('1.0', 'UTF-8');
        $this->dom->formatOutput = true;

        $root = $this->dom->createElementNS(self::UBL_NS, 'Invoice');
        $root->setAttribute('xmlns:cac', self::CAC_NS);
        $root->setAttribute('xmlns:cbc', self::CBC_NS);
        $this->dom->appendChild($root);

        $this->addCbc($root, 'CustomizationID', self::CUSTOMIZATION);
        $this->addCbc($root, 'ProfileID', self::PROFILE_ID);
        $this->addCbc($root, 'ID', $data->invoiceNumber);
        $this->addCbc($root, 'IssueDate', $data->invoiceDate);
        $this->addCbc($root, 'DueDate', $data->dueDate);
        $this->addCbc($root, 'InvoiceTypeCode', $data->documentType);
        $this->addCbc($root, 'DocumentCurrencyCode', $data->currencyCode);
        $this->addCbc($root, 'TaxCurrencyCode', $data->currencyCode);

        if ($data->creditNoteReference !== null) {
            $billingRef = $this->addCac($root, 'BillingReference');
            $invoiceDocRef = $this->addCac($billingRef, 'InvoiceDocumentReference');
            $this->addCbc($invoiceDocRef, 'ID', $data->creditNoteReference);
        }

        $this->addParty($root, 'AccountingSupplierParty', $data->supplierName, $data->supplierTrn, $data->supplierStreet, $data->supplierCity, $data->supplierCountry);
        $this->addParty($root, 'AccountingCustomerParty', $data->customerName, $data->customerTrn, $data->customerStreet, $data->customerCity, $data->customerCountry);

        $this->addPaymentMeans($root);
        $this->addTaxTotal($root, $data->vatAmount, $data->vatRate);
        $this->addLegalMonetaryTotal($root, $data);
        $this->addInvoiceLines($root, $data->lines);

        return $this->dom->saveXML();
    }

    // ----------------------------------------------------------------
    // Party
    // ----------------------------------------------------------------

    private function addParty(DOMElement $parent, string $wrapper, string $name, ?string $trn, string $street, string $city, string $country): void
    {
        $supplierParty = $this->addCac($parent, $wrapper);
        $party = $this->addCac($supplierParty, 'Party');

        if ($trn !== null) {
            $partyTaxScheme = $this->addCac($party, 'PartyTaxScheme');
            $this->addCbc($partyTaxScheme, 'CompanyID', $trn);
            $taxScheme = $this->addCac($partyTaxScheme, 'TaxScheme');
            $this->addCbc($taxScheme, 'ID', 'VAT');
        }

        $partyName = $this->addCac($party, 'PartyName');
        $this->addCbc($partyName, 'Name', $name);

        $postalAddress = $this->addCac($party, 'PostalAddress');
        $this->addCbc($postalAddress, 'StreetName', $street);
        $this->addCbc($postalAddress, 'CityName', $city);
        $addressCountry = $this->addCac($postalAddress, 'Country');
        $this->addCbc($addressCountry, 'IdentificationCode', $country);
    }

    // ----------------------------------------------------------------
    // Tax / Totals
    // ----------------------------------------------------------------

    private function addPaymentMeans(DOMElement $parent): void
    {
        $pm = $this->addCac($parent, 'PaymentMeans');
        $this->addCbc($pm, 'PaymentMeansCode', '30'); // credit transfer
    }

    private function addTaxTotal(DOMElement $parent, float $vatAmount, float $vatRate): void
    {
        $taxTotal = $this->addCac($parent, 'TaxTotal');
        $this->addCbc($taxTotal, 'TaxAmount', number_format($vatAmount, 2, '.', ''), ['currencyID' => 'AED']);

        $taxSubtotal = $this->addCac($taxTotal, 'TaxSubtotal');
        $this->addCbc($taxSubtotal, 'TaxAmount', number_format($vatAmount, 2, '.', ''), ['currencyID' => 'AED']);
        $taxCategory = $this->addCac($taxSubtotal, 'TaxCategory');
        $this->addCbc($taxCategory, 'ID', $vatRate > 0 ? 'S' : 'Z');
        $this->addCbc($taxCategory, 'Percent', number_format($vatRate * 100, 2, '.', ''));
        $taxScheme = $this->addCac($taxCategory, 'TaxScheme');
        $this->addCbc($taxScheme, 'ID', 'VAT');
    }

    private function addLegalMonetaryTotal(DOMElement $parent, FtaInvoiceData $data): void
    {
        $lmt = $this->addCac($parent, 'LegalMonetaryTotal');
        $this->addCbc($lmt, 'LineExtensionAmount', number_format($data->lineExtensionAmount, 2, '.', ''), ['currencyID' => 'AED']);
        $this->addCbc($lmt, 'TaxExclusiveAmount', number_format($data->taxExclusiveAmount, 2, '.', ''), ['currencyID' => 'AED']);
        $this->addCbc($lmt, 'TaxInclusiveAmount', number_format($data->taxInclusiveAmount, 2, '.', ''), ['currencyID' => 'AED']);
        $this->addCbc($lmt, 'PayableAmount', number_format($data->payableAmount, 2, '.', ''), ['currencyID' => 'AED']);
    }

    // ----------------------------------------------------------------
    // Invoice lines
    // ----------------------------------------------------------------

    private function addInvoiceLines(DOMElement $parent, array $lines): void
    {
        foreach ($lines as $i => $line) {
            $il = $this->addCac($parent, 'InvoiceLine');
            $this->addCbc($il, 'ID', (string) ($i + 1));
            $this->addCbc($il, 'InvoicedQuantity', (string) ($line['quantity'] ?? 1), ['unitCode' => $line['unit_code'] ?? 'PCE']);
            $this->addCbc($il, 'LineExtensionAmount', number_format((float) ($line['net_amount'] ?? 0), 2, '.', ''), ['currencyID' => 'AED']);

            // Tax
            $lineTaxTotal = $this->addCac($il, 'TaxTotal');
            $this->addCbc($lineTaxTotal, 'TaxAmount', number_format((float) ($line['tax_amount'] ?? 0), 2, '.', ''), ['currencyID' => 'AED']);
            $lineTaxSubtotal = $this->addCac($lineTaxTotal, 'TaxSubtotal');
            $this->addCbc($lineTaxSubtotal, 'TaxAmount', number_format((float) ($line['tax_amount'] ?? 0), 2, '.', ''), ['currencyID' => 'AED']);
            $lineTaxCat = $this->addCac($lineTaxSubtotal, 'TaxCategory');
            $this->addCbc($lineTaxCat, 'ID', 'S');
            $this->addCbc($lineTaxCat, 'Percent', '5.00');
            $lineTaxScheme = $this->addCac($lineTaxCat, 'TaxScheme');
            $this->addCbc($lineTaxScheme, 'ID', 'VAT');

            // Item
            $item = $this->addCac($il, 'Item');
            $this->addCbc($item, 'Name', $line['description'] ?? '');

            // Price
            $price = $this->addCac($il, 'Price');
            $this->addCbc($price, 'PriceAmount', number_format((float) ($line['unit_price'] ?? 0), 2, '.', ''), ['currencyID' => 'AED']);
        }
    }

    // ----------------------------------------------------------------
    // DOM helpers
    // ----------------------------------------------------------------

    private function addCbc(DOMElement $parent, string $name, string $value, array $attrs = []): DOMElement
    {
        $el = $this->dom->createElementNS(self::CBC_NS, "cbc:{$name}", htmlspecialchars($value, ENT_XML1));
        foreach ($attrs as $k => $v) {
            $el->setAttribute($k, $v);
        }
        $parent->appendChild($el);
        return $el;
    }

    private function addCac(DOMElement $parent, string $name): DOMElement
    {
        $el = $this->dom->createElementNS(self::CAC_NS, "cac:{$name}");
        $parent->appendChild($el);
        return $el;
    }
}
