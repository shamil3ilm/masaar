<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Domains\Compliance\Fatoora\DTOs\AddressData;
use App\Domains\Compliance\Fatoora\DTOs\InvoiceXmlData;
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
        $this->addAllowanceCharge($data);
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
     *
     * Element order per UBL 2.1 and ZATCA specifications:
     * 1. UBLVersionID
     * 2. CustomizationID
     * 3. ProfileID
     * 4. ID
     * 5. UUID
     * 6. IssueDate
     * 7. IssueTime
     * 8. InvoiceTypeCode
     * 9. DocumentCurrencyCode
     * 10. TaxCurrencyCode
     */
    private function addInvoiceIdentification(InvoiceXmlData $data): void
    {
        // UBL Version ID (mandatory per ZATCA)
        $this->addElement('cbc:UBLVersionID', '2.1');

        // Customization ID (ZATCA-specific UBL customization)
        $this->addElement('cbc:CustomizationID', 'urn:oasis:names:specification:ubl:xpath:Invoice-2.0:sac-mod');

        // Profile ID (ZATCA Phase 2 spec):
        // - Simplified B2C invoices → "reporting:1.0"
        // - Standard B2B invoices   → "clearance:1.0"
        $profileId = $data->isSimplified() ? 'reporting:1.0' : 'clearance:1.0';
        $this->addElement('cbc:ProfileID', $profileId);

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

        // Note (KSA-10: required for credit/debit notes per BR-KSA-17)
        if ($data->creditDebitReason !== null) {
            $this->addElement('cbc:Note', $data->creditDebitReason);
        }

        // Document currency (can be foreign currency like USD, EUR)
        $this->addElement('cbc:DocumentCurrencyCode', $data->currency);

        // Tax currency - ALWAYS SAR for Saudi VAT per ZATCA requirements
        // BT-6: When document is in foreign currency, tax amounts must still be reported in SAR
        $taxCurrency = $data->isMultiCurrency() ? 'SAR' : $data->currency;
        $this->addElement('cbc:TaxCurrencyCode', $taxCurrency);
    }

    /**
     * Add billing reference (for credit/debit notes and prepayment links).
     *
     * Per ZATCA:
     * - Credit/debit notes MUST reference the original invoice
     * - Final invoices MAY reference prepayment/deposit invoices
     */
    private function addBillingReference(InvoiceXmlData $data): void
    {
        // Add billing reference for credit/debit notes (required)
        if ($data->billingReferenceId !== null) {
            $billingRef = $this->dom->createElementNS(self::CAC_NS, 'cac:BillingReference');
            $invoiceRef = $this->dom->createElementNS(self::CAC_NS, 'cac:InvoiceDocumentReference');
            $invoiceRef->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:ID', $data->billingReferenceId));

            $billingRef->appendChild($invoiceRef);
            $this->root->appendChild($billingRef);
        }

        // Add prepayment invoice references (for final invoices referencing deposits)
        // This creates audit trail linking final invoice to deposit invoices
        if ($data->hasPrepaymentReferences()) {
            foreach ($data->prepaymentInvoiceIds as $prepaymentId) {
                $billingRef = $this->dom->createElementNS(self::CAC_NS, 'cac:BillingReference');
                $invoiceRef = $this->dom->createElementNS(self::CAC_NS, 'cac:InvoiceDocumentReference');
                $invoiceRef->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:ID', $prepaymentId));

                // Add document type code to distinguish prepayment references
                $docTypeCode = $this->dom->createElementNS(self::CBC_NS, 'cbc:DocumentTypeCode', '386');
                $invoiceRef->appendChild($docTypeCode);

                $billingRef->appendChild($invoiceRef);
                $this->root->appendChild($billingRef);
            }
        }
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

        // QR Code - only add if not empty (will be set during signing)
        // Empty QR codes cause BR-CL-KSA-14 validation error
        // The setQrCode() method can be used to add QR after invoice generation
    }

    /**
     * Add supplier (seller) party.
     *
     * Per ZATCA specifications, use CRN as primary PartyIdentification if available,
     * VAT number goes in PartyTaxScheme/CompanyID.
     */
    private function addSupplierParty(InvoiceXmlData $data): void
    {
        $supplier = $this->dom->createElementNS(self::CAC_NS, 'cac:AccountingSupplierParty');
        $party = $this->dom->createElementNS(self::CAC_NS, 'cac:Party');

        // Party identification - use CRN if available, otherwise use VAT
        // ZATCA samples show single PartyIdentification with CRN
        $partyId = $this->dom->createElementNS(self::CAC_NS, 'cac:PartyIdentification');
        if ($data->sellerCrNumber !== null) {
            $idElement = $this->dom->createElementNS(self::CBC_NS, 'cbc:ID', $data->sellerCrNumber);
            $idElement->setAttribute('schemeID', 'CRN');
        } else {
            $idElement = $this->dom->createElementNS(self::CBC_NS, 'cbc:ID', $data->sellerVatNumber);
            $idElement->setAttribute('schemeID', 'VAT');
        }
        $partyId->appendChild($idElement);
        $party->appendChild($partyId);

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
     *
     * Per ZATCA specification:
     * - If buyer has VAT: No PartyIdentification, only PartyTaxScheme with CompanyID
     * - If buyer has NO VAT: PartyIdentification required with other schemeID (TIN, CRN, NAT, etc.)
     *
     * Valid schemeIDs for non-VAT buyers:
     * - TIN: Tax Identification Number
     * - CRN: Commercial Registration Number
     * - MOM: Momra License
     * - MLS: MLSD License
     * - SAG: Sagia License
     * - NAT: National ID (Saudis)
     * - GCC: GCC ID
     * - IQA: Iqama Number
     * - PAS: Passport Number
     * - OTH: Other ID
     */
    private function addCustomerParty(InvoiceXmlData $data): void
    {
        $customer = $this->dom->createElementNS(self::CAC_NS, 'cac:AccountingCustomerParty');
        $party = $this->dom->createElementNS(self::CAC_NS, 'cac:Party');

        // Party identification - required when buyer is NOT VAT registered
        // Per ZATCA: buyers without VAT must have alternative ID
        if (! $data->buyerHasVat() && $data->buyerHasAlternativeId()) {
            $partyId = $this->dom->createElementNS(self::CAC_NS, 'cac:PartyIdentification');
            $idElement = $this->dom->createElementNS(self::CBC_NS, 'cbc:ID', $data->buyerId);
            $idElement->setAttribute('schemeID', $data->buyerIdScheme);
            $partyId->appendChild($idElement);
            $party->appendChild($partyId);
        }

        // Postal address (if provided)
        if ($data->buyerAddress !== null) {
            $party->appendChild($this->buildAddress($data->buyerAddress));
        }

        // Party tax scheme (for B2B with VAT)
        if ($data->buyerHasVat()) {
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
     * Add document-level allowance/charge (discount).
     *
     * ZATCA requires cac:AllowanceCharge element for document-level discounts.
     * Per UBL 2.1 and ZATCA specifications, this must appear before TaxTotal.
     */
    private function addAllowanceCharge(InvoiceXmlData $data): void
    {
        if ($data->discount <= 0) {
            return;
        }

        $allowanceCharge = $this->dom->createElementNS(self::CAC_NS, 'cac:AllowanceCharge');

        // ChargeIndicator: false = allowance (discount), true = charge
        $allowanceCharge->appendChild(
            $this->dom->createElementNS(self::CBC_NS, 'cbc:ChargeIndicator', 'false')
        );

        // AllowanceChargeReasonCode (ZATCA recommendation: 95 = discount)
        $allowanceCharge->appendChild(
            $this->dom->createElementNS(self::CBC_NS, 'cbc:AllowanceChargeReasonCode', '95')
        );

        // AllowanceChargeReason (required for ZATCA)
        $reason = $data->discountReason ?? 'Discount';
        $allowanceCharge->appendChild(
            $this->dom->createElementNS(self::CBC_NS, 'cbc:AllowanceChargeReason', $reason)
        );

        // MultiplierFactorNumeric (optional, but good for clarity)
        // Not adding as it's optional and we have the Amount

        // Amount
        $amount = $this->dom->createElementNS(
            self::CBC_NS,
            'cbc:Amount',
            $this->formatAmount($data->discount)
        );
        $amount->setAttribute('currencyID', $data->currency);
        $allowanceCharge->appendChild($amount);

        // BaseAmount (the amount before discount, which is subtotal + discount)
        $baseAmount = $this->dom->createElementNS(
            self::CBC_NS,
            'cbc:BaseAmount',
            $this->formatAmount($data->subtotal + $data->discount)
        );
        $baseAmount->setAttribute('currencyID', $data->currency);
        $allowanceCharge->appendChild($baseAmount);

        // TaxCategory for the discount
        $taxCategoryCode = $data->discountTaxCategory ?? 'S';
        $taxRate = $data->discountTaxRate ?? 15.0;

        $taxCategory = $this->dom->createElementNS(self::CAC_NS, 'cac:TaxCategory');
        $taxCategory->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:ID', $taxCategoryCode));
        $taxCategory->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:Percent', $this->formatAmount($taxRate)));
        $taxScheme = $this->dom->createElementNS(self::CAC_NS, 'cac:TaxScheme');
        $taxScheme->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:ID', 'VAT'));
        $taxCategory->appendChild($taxScheme);
        $allowanceCharge->appendChild($taxCategory);

        $this->root->appendChild($allowanceCharge);
    }

    /**
     * Add tax total with subtotals per category.
     *
     * MULTI-CURRENCY SUPPORT (per ZATCA spec):
     * When document currency differs from SAR (e.g., USD, EUR):
     * - BT-110: Invoice total VAT amount (in document currency)
     * - BT-111: Invoice total VAT amount in accounting currency (SAR) - REQUIRED
     *
     * First TaxTotal: Document currency with subtotals
     * Second TaxTotal: SAR amount only (required for BT-111 when multi-currency)
     */
    private function addTaxTotal(InvoiceXmlData $data): void
    {
        $isMultiCurrency = $data->isMultiCurrency();

        // Calculate SAR tax amount for multi-currency invoices
        $taxAmountSar = $isMultiCurrency
            ? $data->convertToSar($data->taxAmount)
            : $data->taxAmount;

        // First TaxTotal - document currency with subtotals (BT-110)
        $taxTotal = $this->dom->createElementNS(self::CAC_NS, 'cac:TaxTotal');

        // Total tax amount in document currency
        $taxAmount = $this->dom->createElementNS(self::CBC_NS, 'cbc:TaxAmount', $this->formatAmount($data->taxAmount));
        $taxAmount->setAttribute('currencyID', $data->currency);
        $taxTotal->appendChild($taxAmount);

        // Tax subtotals by category (in document currency)
        if (! empty($data->taxSubtotals)) {
            foreach ($data->taxSubtotals as $subtotal) {
                $taxTotal->appendChild($this->buildTaxSubtotal($subtotal, $data->currency));
            }
        } else {
            // Aggregate tax subtotals from invoice lines by category and rate
            $aggregated = $this->aggregateTaxSubtotals($data->lines);
            foreach ($aggregated as $subtotal) {
                $taxTotal->appendChild($this->buildTaxSubtotal($subtotal, $data->currency));
            }
        }

        $this->root->appendChild($taxTotal);

        // Second TaxTotal - SAR amount only (BT-111, required for multi-currency)
        // Per ZATCA: "If the VAT accounting currency code (BT-6) is present,
        // then the Invoice total VAT amount in accounting currency (BT-111) shall be provided"
        $taxTotal2 = $this->dom->createElementNS(self::CAC_NS, 'cac:TaxTotal');
        $taxAmount2 = $this->dom->createElementNS(
            self::CBC_NS,
            'cbc:TaxAmount',
            $this->formatAmount($isMultiCurrency ? $taxAmountSar : $data->taxAmount)
        );
        // For multi-currency: SAR; for SAR invoices: same currency
        $taxAmount2->setAttribute('currencyID', $isMultiCurrency ? 'SAR' : $data->currency);
        $taxTotal2->appendChild($taxAmount2);
        $this->root->appendChild($taxTotal2);
    }

    /**
     * Aggregate tax subtotals from invoice lines by category and rate.
     *
     * @param  array  $lines  Invoice lines
     * @return array Aggregated subtotals
     */
    private function aggregateTaxSubtotals(array $lines): array
    {
        $subtotals = [];

        foreach ($lines as $line) {
            $category = $line['taxCategory'] ?? 'S';
            $rate = (float) ($line['taxRate'] ?? 15.0);
            $key = $category.'_'.$rate;

            if (! isset($subtotals[$key])) {
                $subtotals[$key] = [
                    'taxableAmount' => 0.0,
                    'taxAmount' => 0.0,
                    'taxPercent' => $rate,
                    'taxCategory' => $category,
                    'taxExemptionReason' => $line['taxExemptionReason'] ?? null,
                    'taxExemptionReasonCode' => $line['taxExemptionReasonCode'] ?? null,
                ];
            }

            // Calculate line taxable amount (lineTotal is already net: quantity × unitPrice)
            $lineNet = (float) ($line['lineTotal'] ?? ($line['quantity'] * $line['unitPrice']));
            $subtotals[$key]['taxableAmount'] += $lineNet;
            $subtotals[$key]['taxAmount'] += $line['taxAmount'] ?? 0;
        }

        return array_values($subtotals);
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
     *
     * Handles:
     * - Standard priced items
     * - Tax-inclusive pricing (converted to net)
     * - Free goods/samples with market value for VAT (deemed supply)
     * - Line-level discounts
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

            // Calculate net amounts (handle tax-inclusive pricing and free goods)
            $taxRate = (float) ($line['taxRate'] ?? 15.0);
            $lineDiscount = (float) ($line['discount'] ?? 0);

            // Determine effective price:
            // - For free goods: Use market value (deemed supply for VAT)
            // - For regular items: Use unit price
            $isFreeItem = isset($line['isFreeItem']) && $line['isFreeItem'] === true;
            $unitPrice = $isFreeItem
                ? (float) ($line['marketValue'] ?? 0.0)
                : (float) $line['unitPrice'];

            if ($data->isTaxInclusive && ! $isFreeItem) {
                // Convert tax-inclusive prices to tax-exclusive for ZATCA XML
                $netUnitPrice = InvoiceXmlData::calculateNetFromGross($unitPrice, $taxRate);
                $lineNet = round($netUnitPrice * $line['quantity'], 2) - $lineDiscount;
                $lineTaxAmount = InvoiceXmlData::calculateTaxFromNet($lineNet, $taxRate);
            } else {
                // Already tax-exclusive (or free item with market value)
                $netUnitPrice = $unitPrice;
                $lineNet = (float) ($line['lineTotal'] ?? ($line['quantity'] * $unitPrice)) - $lineDiscount;
                $lineTaxAmount = (float) ($line['taxAmount'] ?? InvoiceXmlData::calculateTaxFromNet($lineNet, $taxRate));
            }

            // For free items: lineNet = 0 but VAT still calculated on market value
            // The invoice XML shows 0 amount but includes VAT on deemed supply
            $displayLineNet = $isFreeItem ? 0.0 : $lineNet;

            // For free items: Show 0 as line amount but include VAT note
            $lineAmount = $this->dom->createElementNS(
                self::CBC_NS,
                'cbc:LineExtensionAmount',
                $this->formatAmount($displayLineNet)
            );
            $lineAmount->setAttribute('currencyID', $data->currency);
            $invoiceLine->appendChild($lineAmount);

            // Add note for free items indicating deemed supply
            if ($isFreeItem && $unitPrice > 0) {
                $freeNote = $this->dom->createElementNS(
                    self::CBC_NS,
                    'cbc:Note',
                    'Free item - VAT on deemed supply (market value: '.$this->formatAmount($unitPrice).' '.$data->currency.')'
                );
                $invoiceLine->appendChild($freeNote);
            }

            // Line-level AllowanceCharge (discount) if present
            if ($lineDiscount > 0) {
                $invoiceLine->appendChild($this->buildLineAllowanceCharge(
                    $lineDiscount,
                    $line['discountReason'] ?? 'Line discount',
                    $line['taxCategory'] ?? 'S',
                    (float) ($line['taxRate'] ?? 15),
                    $data->currency
                ));
            }

            // Tax total for this line (use calculated tax amount for tax-inclusive support)
            $lineTax = $this->dom->createElementNS(self::CAC_NS, 'cac:TaxTotal');
            $lineTaxAmountEl = $this->dom->createElementNS(self::CBC_NS, 'cbc:TaxAmount', $this->formatAmount($lineTaxAmount));
            $lineTaxAmountEl->setAttribute('currencyID', $data->currency);
            $lineTax->appendChild($lineTaxAmountEl);

            // Rounding amount (set to 0)
            $rounding = $this->dom->createElementNS(self::CBC_NS, 'cbc:RoundingAmount', '0.00');
            $rounding->setAttribute('currencyID', $data->currency);
            $lineTax->appendChild($rounding);

            $invoiceLine->appendChild($lineTax);

            // Item
            $item = $this->dom->createElementNS(self::CAC_NS, 'cac:Item');
            $item->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:Name', $line['description']));

            // Item classification code (UNSPSC or GS1) if provided
            if (! empty($line['itemClassificationCode'])) {
                $commodityClass = $this->dom->createElementNS(self::CAC_NS, 'cac:CommodityClassification');
                $classCode = $this->dom->createElementNS(
                    self::CBC_NS,
                    'cbc:ItemClassificationCode',
                    $line['itemClassificationCode']
                );
                $classCode->setAttribute('listID', 'UNSPSC');
                $commodityClass->appendChild($classCode);
                $item->appendChild($commodityClass);
            }

            // Item tax category
            $itemTaxCat = $this->dom->createElementNS(self::CAC_NS, 'cac:ClassifiedTaxCategory');
            $itemTaxCat->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:ID', $line['taxCategory'] ?? 'S'));
            $itemTaxCat->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:Percent', $this->formatAmount($line['taxRate'] ?? 15)));
            $itemTaxScheme = $this->dom->createElementNS(self::CAC_NS, 'cac:TaxScheme');
            $itemTaxScheme->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:ID', 'VAT'));
            $itemTaxCat->appendChild($itemTaxScheme);
            $item->appendChild($itemTaxCat);

            $invoiceLine->appendChild($item);

            // Price (net unit price - ZATCA requires tax-exclusive price in XML)
            $price = $this->dom->createElementNS(self::CAC_NS, 'cac:Price');
            $priceAmount = $this->dom->createElementNS(self::CBC_NS, 'cbc:PriceAmount', $this->formatAmount($netUnitPrice));
            $priceAmount->setAttribute('currencyID', $data->currency);
            $price->appendChild($priceAmount);

            // BaseQuantity (optional but good for clarity)
            $baseQty = $this->dom->createElementNS(self::CBC_NS, 'cbc:BaseQuantity', '1');
            $baseQty->setAttribute('unitCode', $line['unitCode'] ?? 'PCE');
            $price->appendChild($baseQty);

            $invoiceLine->appendChild($price);

            $this->root->appendChild($invoiceLine);
        }
    }

    /**
     * Build line-level AllowanceCharge element.
     */
    private function buildLineAllowanceCharge(
        float $amount,
        string $reason,
        string $taxCategory,
        float $taxRate,
        string $currency
    ): DOMElement {
        $allowanceCharge = $this->dom->createElementNS(self::CAC_NS, 'cac:AllowanceCharge');

        // ChargeIndicator: false = allowance (discount)
        $allowanceCharge->appendChild(
            $this->dom->createElementNS(self::CBC_NS, 'cbc:ChargeIndicator', 'false')
        );

        // AllowanceChargeReasonCode (95 = discount)
        $allowanceCharge->appendChild(
            $this->dom->createElementNS(self::CBC_NS, 'cbc:AllowanceChargeReasonCode', '95')
        );

        // AllowanceChargeReason
        $allowanceCharge->appendChild(
            $this->dom->createElementNS(self::CBC_NS, 'cbc:AllowanceChargeReason', $reason)
        );

        // Amount
        $amountEl = $this->dom->createElementNS(self::CBC_NS, 'cbc:Amount', $this->formatAmount($amount));
        $amountEl->setAttribute('currencyID', $currency);
        $allowanceCharge->appendChild($amountEl);

        return $allowanceCharge;
    }

    /**
     * Build address element.
     *
     * Element order per UBL 2.1 and ZATCA specifications:
     * 1. StreetName
     * 2. AdditionalStreetName (optional)
     * 3. BuildingNumber
     * 4. PlotIdentification (optional)
     * 5. CitySubdivisionName (district)
     * 6. CityName
     * 7. PostalZone
     * 8. CountrySubentity (optional, region/state)
     * 9. Country/IdentificationCode
     */
    private function buildAddress(AddressData $address): DOMElement
    {
        $postalAddress = $this->dom->createElementNS(self::CAC_NS, 'cac:PostalAddress');

        // 1. StreetName (mandatory)
        $postalAddress->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:StreetName', $address->street));

        // 2. AdditionalStreetName (optional)
        if ($address->additionalStreet !== null) {
            $postalAddress->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:AdditionalStreetName', $address->additionalStreet));
        }

        // 3. BuildingNumber (mandatory per ZATCA)
        if ($address->buildingNumber !== null) {
            $postalAddress->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:BuildingNumber', $address->buildingNumber));
        }

        // 4. PlotIdentification (optional - for plot/land identification)
        if ($address->plotIdentification !== null) {
            $postalAddress->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:PlotIdentification', $address->plotIdentification));
        }

        // 5. CitySubdivisionName (district - mandatory per ZATCA)
        if ($address->district !== null) {
            $postalAddress->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:CitySubdivisionName', $address->district));
        }

        // 6. CityName (mandatory)
        $postalAddress->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:CityName', $address->city));

        // 7. PostalZone (mandatory)
        $postalAddress->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:PostalZone', $address->postalCode));

        // 8. CountrySubentity (optional - region/state)
        if ($address->countrySubentity !== null) {
            $postalAddress->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:CountrySubentity', $address->countrySubentity));
        }

        // 9. Country (mandatory)
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
     * Set QR code in XML (creates element if not exists).
     */
    public function setQrCode(string $qrCode): void
    {
        $xpath = new \DOMXPath($this->dom);
        $xpath->registerNamespace('cac', self::CAC_NS);
        $xpath->registerNamespace('cbc', self::CBC_NS);

        $nodes = $xpath->query("//cac:AdditionalDocumentReference[cbc:ID='QR']/cac:Attachment/cbc:EmbeddedDocumentBinaryObject");

        if ($nodes->length > 0) {
            $nodes->item(0)->nodeValue = $qrCode;
        } else {
            // Create QR code element if it doesn't exist
            $this->addQrCodeElement($qrCode);
        }
    }

    /**
     * Add QR code AdditionalDocumentReference element.
     */
    private function addQrCodeElement(string $qrCode): void
    {
        // Find the PIH element to insert QR after it
        $xpath = new \DOMXPath($this->dom);
        $xpath->registerNamespace('cac', self::CAC_NS);
        $xpath->registerNamespace('cbc', self::CBC_NS);

        $pihNodes = $xpath->query("//cac:AdditionalDocumentReference[cbc:ID='PIH']");

        $qr = $this->dom->createElementNS(self::CAC_NS, 'cac:AdditionalDocumentReference');
        $qr->appendChild($this->dom->createElementNS(self::CBC_NS, 'cbc:ID', 'QR'));
        $qrAttachment = $this->dom->createElementNS(self::CAC_NS, 'cac:Attachment');
        $qrBinary = $this->dom->createElementNS(self::CBC_NS, 'cbc:EmbeddedDocumentBinaryObject', $qrCode);
        $qrBinary->setAttribute('mimeCode', 'text/plain');
        $qrAttachment->appendChild($qrBinary);
        $qr->appendChild($qrAttachment);

        if ($pihNodes->length > 0) {
            // Insert after PIH
            $pihNode = $pihNodes->item(0);
            if ($pihNode->nextSibling) {
                $this->root->insertBefore($qr, $pihNode->nextSibling);
            } else {
                $this->root->appendChild($qr);
            }
        } else {
            $this->root->appendChild($qr);
        }
    }
}
