<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\Services;

use App\Domains\Compliance\Zatca\DTOs\InvoiceXmlData;

/**
 * ZATCA invoice XML builder.
 *
 * Generates UBL 2.1 compliant XML for ZATCA e-invoicing.
 * Simplified implementation - extend for full UBL compliance.
 */
class XmlBuilder
{
    private const UBL_NS = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
    private const CAC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    private const CBC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    /**
     * Build invoice XML.
     */
    public function build(InvoiceXmlData $data): string
    {
        $xml = new \SimpleXMLElement(
            '<?xml version="1.0" encoding="UTF-8"?>' .
            '<Invoice xmlns="' . self::UBL_NS . '" ' .
            'xmlns:cac="' . self::CAC_NS . '" ' .
            'xmlns:cbc="' . self::CBC_NS . '"/>'
        );

        // Invoice identification
        $xml->addChild('cbc:ID', $data->invoiceNumber, self::CBC_NS);
        $xml->addChild('cbc:UUID', $data->uuid, self::CBC_NS);
        $xml->addChild('cbc:IssueDate', $data->issueDate, self::CBC_NS);
        $xml->addChild('cbc:IssueTime', $data->issueTime, self::CBC_NS);
        $xml->addChild('cbc:InvoiceTypeCode', $data->invoiceTypeCode, self::CBC_NS);
        $xml->addChild('cbc:DocumentCurrencyCode', $data->currency, self::CBC_NS);

        // Previous invoice hash (for chaining)
        if ($data->previousInvoiceHash !== null) {
            $additionalRef = $xml->addChild('cac:AdditionalDocumentReference', null, self::CAC_NS);
            $additionalRef->addChild('cbc:ID', 'PIH', self::CBC_NS);
            $attachment = $additionalRef->addChild('cac:Attachment', null, self::CAC_NS);
            $attachment->addChild('cbc:EmbeddedDocumentBinaryObject', $data->previousInvoiceHash, self::CBC_NS);
        }

        // Seller
        $supplier = $xml->addChild('cac:AccountingSupplierParty', null, self::CAC_NS);
        $party = $supplier->addChild('cac:Party', null, self::CAC_NS);
        $partyId = $party->addChild('cac:PartyIdentification', null, self::CAC_NS);
        $partyId->addChild('cbc:ID', $data->sellerVatNumber, self::CBC_NS);
        $partyName = $party->addChild('cac:PartyName', null, self::CAC_NS);
        $partyName->addChild('cbc:Name', $data->sellerName, self::CBC_NS);

        // Buyer
        $customer = $xml->addChild('cac:AccountingCustomerParty', null, self::CAC_NS);
        $buyerParty = $customer->addChild('cac:Party', null, self::CAC_NS);
        if ($data->buyerVatNumber !== null) {
            $buyerPartyId = $buyerParty->addChild('cac:PartyIdentification', null, self::CAC_NS);
            $buyerPartyId->addChild('cbc:ID', $data->buyerVatNumber, self::CBC_NS);
        }
        $buyerPartyName = $buyerParty->addChild('cac:PartyName', null, self::CAC_NS);
        $buyerPartyName->addChild('cbc:Name', $data->buyerName, self::CBC_NS);

        // Tax total
        $taxTotal = $xml->addChild('cac:TaxTotal', null, self::CAC_NS);
        $taxTotal->addChild('cbc:TaxAmount', number_format($data->taxAmount, 2, '.', ''), self::CBC_NS)
            ->addAttribute('currencyID', $data->currency);

        // Legal monetary total
        $legalTotal = $xml->addChild('cac:LegalMonetaryTotal', null, self::CAC_NS);
        $legalTotal->addChild('cbc:TaxExclusiveAmount', number_format($data->subtotal, 2, '.', ''), self::CBC_NS)
            ->addAttribute('currencyID', $data->currency);
        $legalTotal->addChild('cbc:TaxInclusiveAmount', number_format($data->total, 2, '.', ''), self::CBC_NS)
            ->addAttribute('currencyID', $data->currency);
        $legalTotal->addChild('cbc:PayableAmount', number_format($data->total, 2, '.', ''), self::CBC_NS)
            ->addAttribute('currencyID', $data->currency);

        // Invoice lines
        foreach ($data->lines as $index => $line) {
            $invoiceLine = $xml->addChild('cac:InvoiceLine', null, self::CAC_NS);
            $invoiceLine->addChild('cbc:ID', (string) ($index + 1), self::CBC_NS);
            $invoiceLine->addChild('cbc:InvoicedQuantity', number_format($line['quantity'], 3, '.', ''), self::CBC_NS);
            $invoiceLine->addChild('cbc:LineExtensionAmount', number_format($line['lineTotal'], 2, '.', ''), self::CBC_NS)
                ->addAttribute('currencyID', $data->currency);

            $item = $invoiceLine->addChild('cac:Item', null, self::CAC_NS);
            $item->addChild('cbc:Name', $line['description'], self::CBC_NS);

            $price = $invoiceLine->addChild('cac:Price', null, self::CAC_NS);
            $price->addChild('cbc:PriceAmount', number_format($line['unitPrice'], 2, '.', ''), self::CBC_NS)
                ->addAttribute('currencyID', $data->currency);
        }

        return $xml->asXML();
    }
}
