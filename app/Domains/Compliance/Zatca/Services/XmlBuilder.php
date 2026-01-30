<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\Services;

use App\Domains\Compliance\Zatca\DTOs\AddressData;
use App\Domains\Compliance\Zatca\DTOs\InvoiceXmlData;
use DOMDocument;
use DOMElement;

/**
 * ZATCA invoice XML builder.
 *
 * Generates UBL 2.1 compliant XML for ZATCA e-invoicing Phase 2.
 * Implements all mandatory fields per ZATCA specifications.
 */
class XmlBuilder
{
    private const UBL_NS = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
    private const CAC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    private const CBC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';
    private const EXT_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2';
    private const SIG_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonSignatureComponents-2';
    private const SBC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:SignatureBasicComponents-2';

    private DOMDocument $dom;
    private DOMElement $root;

    /**
     * Build complete invoice XML.
     */
    public function build(InvoiceXmlData $data): string
    {
        $this->initDocument();
        $this->addInvoiceIdentification($data);
        $this->addBillingReference($data);
        $this->addAdditionalDocumentReferences($data);
        $this->addSupplierParty($data);
        $this->addCustomerParty($data);
        $this->addDelivery($data);
        $this->addPaymentMeans($data);
        $this->addTaxTotal($data);
        $this->addLegalMonetaryTotal($data);
        $this->addInvoiceLines($data);

        $this->dom->formatOutput = true;

        return $this->dom->saveXML();
    }

    /**
     * Initialize XML document with namespaces.
     */
    private function initDocument(): void
    {
        $this->dom = new DOMDocument('1.0', 'UTF-8');

        $this->root = $this->dom->createElementNS(self::UBL_NS, 'Invoice');
        $this->root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', self::CAC_NS);
        $this->root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', self::CBC_NS);
        $this->root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ext', self::EXT_NS);

        $this->dom->appendChild($this->root);
    }

    /**
     * Add UBLExtensions placeholder for signature.
     */
    public function addSignatureExtension(): void
    {
        $extensions = $this->dom->createElementNS(self::EXT_NS, 'ext:UBLExtensions');
        $extension = $this->dom->createElementNS(self::EXT_NS, 'ext:UBLExtension');
        $content = $this->dom->createElementNS(self::EXT_NS, 'ext:ExtensionContent');

        // Signature placeholder - will be filled by XAdES signer
        $sigPlaceholder = $this->dom->createComment('SIGNATURE_PLACEHOLDER');
        $content->appendChild($sigPlaceholder);

        $extension->appendChild($content);
        $extensions->appendChild($extension);

        // Insert at the beginning
        $this->root->insertBefore($extensions, $this->root->firstChild);
    }

    /**
     * Add invoice identification fields.
     */
    private function addInvoiceIdentification(InvoiceXmlData $data): void
    {
        // Profile ID (ZATCA specific)
        $this->addElement('cbc:ProfileID', 'reporting:1.0');

        // Invoice ID
        $this->addElement('cbc:ID', $data->invoiceNumber);

        // UUID
        $this->addElement('cbc:UUID', $data->uuid);

        // Issue date and time
        $this->addElement('cbc:IssueDate', $data->issueDate);
        $this->addElement('cbc:IssueTime', $data->issueTime);

        // Invoice type code with name attribute
        $typeCode = $this->addElement('cbc:InvoiceTypeCode', $data->invoiceTypeCode);
        $typeCode->setAttribute('name', $data->getInvoiceTypeName());

        // Document currency
        $this->addElement('cbc:DocumentCurrencyCode', $data->currency);

        // Tax currency (same as document currency for SA)
        $this->addElement('cbc:TaxCurrencyCode', $data->currency);
    }

    /**
     * Add billing reference (for credit/debit notes).
     */
    private function addBillingReference(InvoiceXmlData $data): void
    {
        if ($data->billingReferenceId === null) {
            return;
        }

        $billingRef = $this->dom->createElementNS(self::CAC_NS, 'cac:BillingReference');
        $invoiceRef = $this->dom->createElementNS(self::CAC_NS, 'cac:InvoiceDocumentReference');
        $invoiceRef->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:ID', $data->billingReferenceId));

        $billingRef->appendChild($invoiceRef);
        $this->root->appendChild($billingRef);
    }

    /**
     * Add additional document references (PIH, QR, ICV).
     */
    private function addAdditionalDocumentReferences(InvoiceXmlData $data): void
    {
        // Invoice Counter Value (ICV) - sequential per organization
        $icv = $this->dom->createElementNS(self::CAC_NS, 'cac:AdditionalDocumentReference');
        $icv->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:ID', 'ICV'));
        $icv->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:UUID', (string) $data->icv));
        $this->root->appendChild($icv);

        // Previous Invoice Hash (PIH)
        $pih = $this->dom->createElementNS(self::CAC_NS, 'cac:AdditionalDocumentReference');
        $pih->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:ID', 'PIH'));
        $pihAttachment = $this->dom->createElementNS(self::CAC_NS, 'cac:Attachment');
        $pihBinary = $this->dom->createElementNS(
            self::CBC_NS,
            'cbc:EmbeddedDocumentBinaryObject',
            $data->previousInvoiceHash ?? $this->getDefaultPih()
        );
        $pihBinary->setAttribute('mimeCode', 'text/plain');
        $pihAttachment->appendChild($pihBinary);
        $pih->appendChild($pihAttachment);
        $this->root->appendChild($pih);

        // QR Code placeholder
        $qr = $this->dom->createElementNS(self::CAC_NS, 'cac:AdditionalDocumentReference');
        $qr->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:ID', 'QR'));
        $qrAttachment = $this->dom->createElementNS(self::CAC_NS, 'cac:Attachment');
        $qrBinary = $this->dom->createElementNS(self::CBC_NS, 'cbc:EmbeddedDocumentBinaryObject', '');
        $qrBinary->setAttribute('mimeCode', 'text/plain');
        $qrAttachment->appendChild($qrBinary);
        $qr->appendChild($qrAttachment);
        $this->root->appendChild($qr);
    }

    /**
     * Add supplier (seller) party.
     */
    private function addSupplierParty(InvoiceXmlData $data): void
    {
        $supplier = $this->dom->createElementNS(self::CAC_NS, 'cac:AccountingSupplierParty');
        $party = $this->dom->createElementNS(self::CAC_NS, 'cac:Party');

        // Party identification (VAT number)
        $partyId = $this->dom->createElementNS(self::CAC_NS, 'cac:PartyIdentification');
        $idElement = $this->dom->createElementNS(self::CBC_NS, 'cbc:ID', $data->sellerVatNumber);
        $idElement->setAttribute('schemeID', 'VAT');
        $partyId->appendChild($idElement);
        $party->appendChild($partyId);

        // Commercial registration (if provided)
        if ($data->sellerCrNumber !== null) {
            $crId = $this->dom->createElementNS(self::CAC_NS, 'cac:PartyIdentification');
            $crElement = $this->dom->createElementNS(self::CBC_NS, 'cbc:ID', $data->sellerCrNumber);
            $crElement->setAttribute('schemeID', 'CRN');
            $crId->appendChild($crElement);
            $party->appendChild($crId);
        }

        // Postal address
        $party->appendChild($this->buildAddress($data->sellerAddress));

        // Party tax scheme
        $taxScheme = $this->dom->createElementNS(self::CAC_NS, 'cac:PartyTaxScheme');
        $taxScheme->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:CompanyID', $data->sellerVatNumber));
        $taxSchemeInner = $this->dom->createElementNS(self::CAC_NS, 'cac:TaxScheme');
        $taxSchemeInner->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:ID', 'VAT'));
        $taxScheme->appendChild($taxSchemeInner);
        $party->appendChild($taxScheme);

        // Party legal entity (name)
        $legalEntity = $this->dom->createElementNS(self::CAC_NS, 'cac:PartyLegalEntity');
        $legalEntity->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:RegistrationName', $data->sellerName));
        $party->appendChild($legalEntity);

        $supplier->appendChild($party);
        $this->root->appendChild($supplier);
    }

    /**
     * Add customer (buyer) party.
     */
    private function addCustomerParty(InvoiceXmlData $data): void
    {
        $customer = $this->dom->createElementNS(self::CAC_NS, 'cac:AccountingCustomerParty');
        $party = $this->dom->createElementNS(self::CAC_NS, 'cac:Party');

        // Party identification (VAT number for B2B)
        if ($data->buyerVatNumber !== null) {
            $partyId = $this->dom->createElementNS(self::CAC_NS, 'cac:PartyIdentification');
            $idElement = $this->dom->createElementNS(self::CBC_NS, 'cbc:ID', $data->buyerVatNumber);
            $idElement->setAttribute('schemeID', 'VAT');
            $partyId->appendChild($idElement);
            $party->appendChild($partyId);
        }

        // Postal address (if provided)
        if ($data->buyerAddress !== null) {
            $party->appendChild($this->buildAddress($data->buyerAddress));
        }

        // Party tax scheme (for B2B)
        if ($data->buyerVatNumber !== null) {
            $taxScheme = $this->dom->createElementNS(self::CAC_NS, 'cac:PartyTaxScheme');
            $taxScheme->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:CompanyID', $data->buyerVatNumber));
            $taxSchemeInner = $this->dom->createElementNS(self::CAC_NS, 'cac:TaxScheme');
            $taxSchemeInner->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:ID', 'VAT'));
            $taxScheme->appendChild($taxSchemeInner);
            $party->appendChild($taxScheme);
        }

        // Party legal entity (name)
        $legalEntity = $this->dom->createElementNS(self::CAC_NS, 'cac:PartyLegalEntity');
        $legalEntity->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:RegistrationName', $data->buyerName));
        $party->appendChild($legalEntity);

        $customer->appendChild($party);
        $this->root->appendChild($customer);
    }

    /**
     * Add delivery information.
     */
    private function addDelivery(InvoiceXmlData $data): void
    {
        if ($data->supplyDate === null) {
            return;
        }

        $delivery = $this->dom->createElementNS(self::CAC_NS, 'cac:Delivery');
        $delivery->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:ActualDeliveryDate', $data->supplyDate));
        $this->root->appendChild($delivery);
    }

    /**
     * Add payment means.
     */
    private function addPaymentMeans(InvoiceXmlData $data): void
    {
        $paymentMeans = $this->dom->createElementNS(self::CAC_NS, 'cac:PaymentMeans');
        $paymentMeans->appendChild(
            $this->dom->createElementNS(self::CBC_NS, 'cbc:PaymentMeansCode', $data->paymentMeansCode ?? '10')
        );

        if ($data->paymentTerms !== null) {
            $paymentMeans->appendChild(
                $this->dom->createElementNS(self::CBC_NS, 'cbc:InstructionNote', $data->paymentTerms)
            );
        }

        $this->root->appendChild($paymentMeans);
    }

    /**
     * Add tax total with subtotals per category.
     */
    private function addTaxTotal(InvoiceXmlData $data): void
    {
        $taxTotal = $this->dom->createElementNS(self::CAC_NS, 'cac:TaxTotal');

        // Total tax amount
        $taxAmount = $this->dom->createElementNS(self::CBC_NS, 'cbc:TaxAmount', $this->formatAmount($data->taxAmount));
        $taxAmount->setAttribute('currencyID', $data->currency);
        $taxTotal->appendChild($taxAmount);

        // Tax subtotals by category
        if (! empty($data->taxSubtotals)) {
            foreach ($data->taxSubtotals as $subtotal) {
                $taxTotal->appendChild($this->buildTaxSubtotal($subtotal, $data->currency));
            }
        } else {
            // Default: single VAT category at 15%
            $taxTotal->appendChild($this->buildTaxSubtotal([
                'taxableAmount' => $data->subtotal,
                'taxAmount' => $data->taxAmount,
                'taxPercent' => 15.0,
                'taxCategory' => 'S',
                'taxExemptionReason' => null,
            ], $data->currency));
        }

        $this->root->appendChild($taxTotal);

        // Second TaxTotal for tax currency (if different)
        $taxTotal2 = $this->dom->createElementNS(self::CAC_NS, 'cac:TaxTotal');
        $taxAmount2 = $this->dom->createElementNS(self::CBC_NS, 'cbc:TaxAmount', $this->formatAmount($data->taxAmount));
        $taxAmount2->setAttribute('currencyID', $data->currency);
        $taxTotal2->appendChild($taxAmount2);
        $this->root->appendChild($taxTotal2);
    }

    /**
     * Build tax subtotal element.
     */
    private function buildTaxSubtotal(array $subtotal, string $currency): DOMElement
    {
        $taxSubtotal = $this->dom->createElementNS(self::CAC_NS, 'cac:TaxSubtotal');

        // Taxable amount
        $taxableAmount = $this->dom->createElementNS(
            self::CBC_NS,
            'cbc:TaxableAmount',
            $this->formatAmount($subtotal['taxableAmount'])
        );
        $taxableAmount->setAttribute('currencyID', $currency);
        $taxSubtotal->appendChild($taxableAmount);

        // Tax amount
        $taxAmount = $this->dom->createElementNS(
            self::CBC_NS,
            'cbc:TaxAmount',
            $this->formatAmount($subtotal['taxAmount'])
        );
        $taxAmount->setAttribute('currencyID', $currency);
        $taxSubtotal->appendChild($taxAmount);

        // Tax category
        $taxCategory = $this->dom->createElementNS(self::CAC_NS, 'cac:TaxCategory');
        $taxCategory->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:ID', $subtotal['taxCategory']));
        $taxCategory->appendChild(
            $this->dom->createElementNS(self::CBC_NS, 'cbc:Percent', $this->formatAmount($subtotal['taxPercent']))
        );

        // Tax exemption reason (for zero-rated or exempt)
        if (! empty($subtotal['taxExemptionReason'])) {
            $taxCategory->appendChild(
                $this->dom->createElementNS(self::CBC_NS, 'cbc:TaxExemptionReasonCode', $subtotal['taxExemptionReasonCode'] ?? '')
            );
            $taxCategory->appendChild(
                $this->dom->createElementNS(self::CBC_NS, 'cbc:TaxExemptionReason', $subtotal['taxExemptionReason'])
            );
        }

        $taxScheme = $this->dom->createElementNS(self::CAC_NS, 'cac:TaxScheme');
        $taxScheme->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:ID', 'VAT'));
        $taxCategory->appendChild($taxScheme);

        $taxSubtotal->appendChild($taxCategory);

        return $taxSubtotal;
    }

    /**
     * Add legal monetary total.
     */
    private function addLegalMonetaryTotal(InvoiceXmlData $data): void
    {
        $total = $this->dom->createElementNS(self::CAC_NS, 'cac:LegalMonetaryTotal');

        // Line extension amount (sum of line totals before tax)
        $lineExt = $this->dom->createElementNS(self::CBC_NS, 'cbc:LineExtensionAmount', $this->formatAmount($data->subtotal));
        $lineExt->setAttribute('currencyID', $data->currency);
        $total->appendChild($lineExt);

        // Tax exclusive amount
        $taxExcl = $this->dom->createElementNS(self::CBC_NS, 'cbc:TaxExclusiveAmount', $this->formatAmount($data->subtotal));
        $taxExcl->setAttribute('currencyID', $data->currency);
        $total->appendChild($taxExcl);

        // Tax inclusive amount
        $taxIncl = $this->dom->createElementNS(self::CBC_NS, 'cbc:TaxInclusiveAmount', $this->formatAmount($data->total));
        $taxIncl->setAttribute('currencyID', $data->currency);
        $total->appendChild($taxIncl);

        // Allowance total (discount)
        if ($data->discount > 0) {
            $allowance = $this->dom->createElementNS(self::CBC_NS, 'cbc:AllowanceTotalAmount', $this->formatAmount($data->discount));
            $allowance->setAttribute('currencyID', $data->currency);
            $total->appendChild($allowance);
        }

        // Prepaid amount
        if ($data->prepaidAmount > 0) {
            $prepaid = $this->dom->createElementNS(self::CBC_NS, 'cbc:PrepaidAmount', $this->formatAmount($data->prepaidAmount));
            $prepaid->setAttribute('currencyID', $data->currency);
            $total->appendChild($prepaid);
        }

        // Payable amount
        $payable = $this->dom->createElementNS(
            self::CBC_NS,
            'cbc:PayableAmount',
            $this->formatAmount($data->total - $data->prepaidAmount)
        );
        $payable->setAttribute('currencyID', $data->currency);
        $total->appendChild($payable);

        $this->root->appendChild($total);
    }

    /**
     * Add invoice lines.
     */
    private function addInvoiceLines(InvoiceXmlData $data): void
    {
        foreach ($data->lines as $index => $line) {
            $invoiceLine = $this->dom->createElementNS(self::CAC_NS, 'cac:InvoiceLine');

            // Line ID
            $invoiceLine->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:ID', (string) ($index + 1)));

            // Invoiced quantity
            $quantity = $this->dom->createElementNS(self::CBC_NS, 'cbc:InvoicedQuantity', $this->formatQuantity($line['quantity']));
            $quantity->setAttribute('unitCode', $line['unitCode'] ?? 'PCE');
            $invoiceLine->appendChild($quantity);

            // Line extension amount
            $lineAmount = $this->dom->createElementNS(
                self::CBC_NS,
                'cbc:LineExtensionAmount',
                $this->formatAmount($line['lineTotal'] - ($line['taxAmount'] ?? 0))
            );
            $lineAmount->setAttribute('currencyID', $data->currency);
            $invoiceLine->appendChild($lineAmount);

            // Tax total for this line
            $lineTax = $this->dom->createElementNS(self::CAC_NS, 'cac:TaxTotal');
            $lineTaxAmount = $this->dom->createElementNS(self::CBC_NS, 'cbc:TaxAmount', $this->formatAmount($line['taxAmount'] ?? 0));
            $lineTaxAmount->setAttribute('currencyID', $data->currency);
            $lineTax->appendChild($lineTaxAmount);

            // Rounding amount (set to 0)
            $rounding = $this->dom->createElementNS(self::CBC_NS, 'cbc:RoundingAmount', '0.00');
            $rounding->setAttribute('currencyID', $data->currency);
            $lineTax->appendChild($rounding);

            $invoiceLine->appendChild($lineTax);

            // Item
            $item = $this->dom->createElementNS(self::CAC_NS, 'cac:Item');
            $item->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:Name', $line['description']));

            // Item tax category
            $itemTaxCat = $this->dom->createElementNS(self::CAC_NS, 'cac:ClassifiedTaxCategory');
            $itemTaxCat->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:ID', $line['taxCategory'] ?? 'S'));
            $itemTaxCat->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:Percent', $this->formatAmount($line['taxRate'] ?? 15)));
            $itemTaxScheme = $this->dom->createElementNS(self::CAC_NS, 'cac:TaxScheme');
            $itemTaxScheme->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:ID', 'VAT'));
            $itemTaxCat->appendChild($itemTaxScheme);
            $item->appendChild($itemTaxCat);

            $invoiceLine->appendChild($item);

            // Price
            $price = $this->dom->createElementNS(self::CAC_NS, 'cac:Price');
            $priceAmount = $this->dom->createElementNS(self::CBC_NS, 'cbc:PriceAmount', $this->formatAmount($line['unitPrice']));
            $priceAmount->setAttribute('currencyID', $data->currency);
            $price->appendChild($priceAmount);
            $invoiceLine->appendChild($price);

            $this->root->appendChild($invoiceLine);
        }
    }

    /**
     * Build address element.
     */
    private function buildAddress(AddressData $address): DOMElement
    {
        $postalAddress = $this->dom->createElementNS(self::CAC_NS, 'cac:PostalAddress');

        $postalAddress->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:StreetName', $address->street));

        if ($address->buildingNumber !== null) {
            $postalAddress->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:BuildingNumber', $address->buildingNumber));
        }

        if ($address->additionalStreet !== null) {
            $postalAddress->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:AdditionalStreetName', $address->additionalStreet));
        }

        if ($address->district !== null) {
            $postalAddress->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:CitySubdivisionName', $address->district));
        }

        $postalAddress->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:CityName', $address->city));
        $postalAddress->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:PostalZone', $address->postalCode));

        $country = $this->dom->createElementNS(self::CAC_NS, 'cac:Country');
        $country->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:IdentificationCode', $address->countryCode));
        $postalAddress->appendChild($country);

        return $postalAddress;
    }

    /**
     * Add simple element to root.
     */
    private function addElement(string $name, string $value): DOMElement
    {
        [$prefix, $localName] = explode(':', $name);
        $ns = $prefix === 'cbc' ? self::CBC_NS : self::CAC_NS;

        $element = $this->dom->createElementNS($ns, $name, htmlspecialchars($value, ENT_XML1));
        $this->root->appendChild($element);

        return $element;
    }

    /**
     * Format monetary amount (2 decimal places).
     */
    private function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * Format quantity (3 decimal places).
     */
    private function formatQuantity(float $quantity): string
    {
        return number_format($quantity, 3, '.', '');
    }

    /**
     * Get default PIH for first invoice (all zeros).
     */
    private function getDefaultPih(): string
    {
        return base64_encode(str_repeat("\0", 32));
    }

    /**
     * Get DOM document for signature injection.
     */
    public function getDocument(): DOMDocument
    {
        return $this->dom;
    }

    /**
     * Update QR code in XML.
     */
    public function setQrCode(string $qrCode): void
    {
        $xpath = new \DOMXPath($this->dom);
        $xpath->registerNamespace('cac', self::CAC_NS);
        $xpath->registerNamespace('cbc', self::CBC_NS);

        $nodes = $xpath->query("//cac:AdditionalDocumentReference[cbc:ID='QR']/cac:Attachment/cbc:EmbeddedDocumentBinaryObject");

        if ($nodes->length > 0) {
            $nodes->item(0)->nodeValue = $qrCode;
        }
    }
}
