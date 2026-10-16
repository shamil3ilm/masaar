<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Domains\Compliance\Fatoora\Config\FatooraConfig;
use App\Domains\Compliance\Fatoora\DTOs\InvoiceXmlData;
use App\Domains\Compliance\Fatoora\Support\Ubl;
use DOMDocument;
use DOMElement;
use InvalidArgumentException;

/**
 * The parts of an invoice that are about the document rather than its money:
 * who it is, what it refers to, when the supply was and how it is paid.
 *
 * Two calls rather than one because UBL puts the parties between them, and
 * element order is part of the schema.
 */
class UblHeaderBuilder
{
    /**
     * Identification, billing references, document references and the
     * signature reference, in that order.
     */
    public function addHeader(DOMDocument $dom, InvoiceXmlData $data): void
    {
        $this->addInvoiceIdentification($dom, $data);
        $this->addBillingReference($dom, $data);
        $this->addAdditionalDocumentReferences($dom, $data);
        $this->addSignatureReference($dom);
    }

    /**
     * Delivery, then payment means.
     */
    public function addTerms(DOMDocument $dom, InvoiceXmlData $data): void
    {
        $this->addDelivery($dom, $data);
        $this->addPaymentMeans($dom, $data);
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
    private function addInvoiceIdentification(DOMDocument $dom, InvoiceXmlData $data): void
    {
        // UBL Version ID (mandatory per ZATCA)
        $this->addElement($dom, 'cbc:UBLVersionID', '2.1');

        // Customization ID (ZATCA-specific UBL customization)
        $this->addElement($dom, 'cbc:CustomizationID', 'urn:oasis:names:specification:ubl:xpath:Invoice-2.0:sac-mod');

        // BT-23, the business process: "reporting:1.0" for every document
        // type. BR-KSA-EN16931-01 admits no other value, and every sample
        // invoice in ZATCA's SDK carries it — standard and simplified,
        // invoice, credit and debit alike.
        //
        // Not "clearance:1.0" for standard invoices: clearance is chosen by
        // the endpoint a document is sent to, not declared inside it.
        $this->addElement($dom, 'cbc:ProfileID', 'reporting:1.0');

        // Invoice ID
        $this->addElement($dom, 'cbc:ID', $data->invoiceNumber);

        // UUID
        $this->addElement($dom, 'cbc:UUID', $data->uuid);

        // Issue date and time
        $this->addElement($dom, 'cbc:IssueDate', $data->issueDate);
        $this->addElement($dom, 'cbc:IssueTime', $data->issueTime);

        // Invoice type code with name attribute
        $typeCode = $this->addElement($dom, 'cbc:InvoiceTypeCode', $data->invoiceTypeCode);
        $typeCode->setAttribute('name', $data->getInvoiceTypeName());

        // KSA-10, the reason a credit or debit note was issued, is emitted by
        // addPaymentMeans() as cbc:InstructionNote — the element BR-KSA-17
        // reads. Not cbc:Note here, which is BT-22, a free-text remark on any
        // invoice and not where the authority looks for the reason.

        // Document currency (can be foreign currency like USD, EUR)
        $this->addElement($dom, 'cbc:DocumentCurrencyCode', $data->currency);

        // Tax currency - ALWAYS SAR for Saudi VAT per ZATCA requirements
        // BT-6: When document is in foreign currency, tax amounts must still be reported in SAR
        $taxCurrency = $data->isMultiCurrency() ? 'SAR' : $data->currency;
        $this->addElement($dom, 'cbc:TaxCurrencyCode', $taxCurrency);
    }

    /**
     * Add billing reference (for credit/debit notes and prepayment links).
     *
     * Per ZATCA:
     * - Credit/debit notes MUST reference the original invoice
     * - Final invoices MAY reference prepayment/deposit invoices
     */
    private function addBillingReference(DOMDocument $dom, InvoiceXmlData $data): void
    {
        $root = $dom->documentElement;

        // Add billing reference for credit/debit notes (required)
        if ($data->billingReferenceId !== null) {
            $billingRef = $dom->createElementNS(Ubl::CAC_NS, 'cac:BillingReference');
            $invoiceRef = $dom->createElementNS(Ubl::CAC_NS, 'cac:InvoiceDocumentReference');
            $invoiceRef->appendChild($dom->createElementNS(Ubl::CBC_NS, 'cbc:ID', $data->billingReferenceId));

            $billingRef->appendChild($invoiceRef);
            $root->appendChild($billingRef);
        }

        // Add prepayment invoice references (for final invoices referencing deposits)
        // This creates audit trail linking final invoice to deposit invoices
        if ($data->hasPrepaymentReferences()) {
            foreach ($data->prepaymentInvoiceIds as $prepaymentId) {
                $billingRef = $dom->createElementNS(Ubl::CAC_NS, 'cac:BillingReference');
                $invoiceRef = $dom->createElementNS(Ubl::CAC_NS, 'cac:InvoiceDocumentReference');
                $invoiceRef->appendChild($dom->createElementNS(Ubl::CBC_NS, 'cbc:ID', $prepaymentId));

                // Add document type code to distinguish prepayment references
                $docTypeCode = $dom->createElementNS(Ubl::CBC_NS, 'cbc:DocumentTypeCode', '386');
                $invoiceRef->appendChild($docTypeCode);

                $billingRef->appendChild($invoiceRef);
                $root->appendChild($billingRef);
            }
        }
    }

    /**
     * Add additional document references (PIH, QR, ICV).
     */
    private function addAdditionalDocumentReferences(DOMDocument $dom, InvoiceXmlData $data): void
    {
        $root = $dom->documentElement;

        // Invoice Counter Value (ICV) - sequential per organization
        $icv = $dom->createElementNS(Ubl::CAC_NS, 'cac:AdditionalDocumentReference');
        $icv->appendChild($dom->createElementNS(Ubl::CBC_NS, 'cbc:ID', 'ICV'));
        $icv->appendChild($dom->createElementNS(Ubl::CBC_NS, 'cbc:UUID', (string) $data->icv));
        $root->appendChild($icv);

        // Previous Invoice Hash (PIH), the genesis value for the first
        // document in a chain.
        $pih = $dom->createElementNS(Ubl::CAC_NS, 'cac:AdditionalDocumentReference');
        $pih->appendChild($dom->createElementNS(Ubl::CBC_NS, 'cbc:ID', 'PIH'));
        $pihAttachment = $dom->createElementNS(Ubl::CAC_NS, 'cac:Attachment');
        $pihBinary = $dom->createElementNS(
            Ubl::CBC_NS,
            'cbc:EmbeddedDocumentBinaryObject',
            $data->previousInvoiceHash ?? FatooraConfig::GENESIS_PIH
        );
        $pihBinary->setAttribute('mimeCode', 'text/plain');
        $pihAttachment->appendChild($pihBinary);
        $pih->appendChild($pihAttachment);
        $root->appendChild($pih);

        // QR Code - only add if not empty (will be set during signing)
        // Empty QR codes cause BR-CL-KSA-14 validation error
        // XmlBuilder::setQrCode() can be used to add QR after invoice generation
    }

    /**
     * The invoice's own statement that it is signed, and how.
     *
     * BR-KSA-30's Schematron asks for //cac:Signature/cbc:SignatureMethod —
     * an element in the body of the invoice, between the document references
     * and the supplier. Despite the rule's name it does not read the
     * extension's URI, so emitting that alone does not satisfy it.
     *
     * Both values are fixed. There is one signature on a ZATCA invoice and one
     * method of making it.
     */
    private function addSignatureReference(DOMDocument $dom): void
    {
        $signature = $dom->createElementNS(Ubl::CAC_NS, 'cac:Signature');
        $signature->appendChild(
            $dom->createElementNS(Ubl::CBC_NS, 'cbc:ID', XmlBuilder::SIGNATURE_REFERENCE)
        );
        $signature->appendChild(
            $dom->createElementNS(Ubl::CBC_NS, 'cbc:SignatureMethod', XmlBuilder::SIGNATURE_URI)
        );

        $dom->documentElement->appendChild($signature);
    }

    /**
     * Add delivery information.
     */
    private function addDelivery(DOMDocument $dom, InvoiceXmlData $data): void
    {
        // The drafter refuses a period without a start or ending before it
        // (BR-KSA-35, BR-KSA-36). This is the last line before a signature,
        // for data that did not come through it: dropping the end date
        // silently would misstate the supply.
        if ($data->supplyEndDate !== null && ($data->supplyDate === null || $data->supplyEndDate < $data->supplyDate)) {
            throw new InvalidArgumentException('The supply end date (KSA-24) needs a supply date (KSA-5) on or before it.');
        }

        if ($data->supplyDate === null) {
            return;
        }

        $delivery = $dom->createElementNS(Ubl::CAC_NS, 'cac:Delivery');
        $delivery->appendChild($dom->createElementNS(Ubl::CBC_NS, 'cbc:ActualDeliveryDate', $data->supplyDate));

        // KSA-24, after the start date as UBL orders them.
        if ($data->supplyEndDate !== null) {
            $delivery->appendChild($dom->createElementNS(Ubl::CBC_NS, 'cbc:LatestDeliveryDate', $data->supplyEndDate));
        }

        $dom->documentElement->appendChild($delivery);
    }

    /**
     * Add payment means.
     */
    private function addPaymentMeans(DOMDocument $dom, InvoiceXmlData $data): void
    {
        $paymentMeans = $dom->createElementNS(Ubl::CAC_NS, 'cac:PaymentMeans');
        $paymentMeans->appendChild(
            $dom->createElementNS(Ubl::CBC_NS, 'cbc:PaymentMeansCode', $data->paymentMeansCode ?? '10')
        );

        // KSA-10. BR-KSA-17 requires a credit or debit note to say why it was
        // issued, and this is the element it reads — ZATCA's own samples carry
        // the reason here and nowhere else. On an ordinary invoice the same
        // element carries the payment terms, so the reason takes precedence
        // when there is one: a note without it is rejected, terms are optional.
        $instruction = $data->creditDebitReason ?? $data->paymentTerms;

        if ($instruction !== null) {
            $paymentMeans->appendChild(
                $dom->createElementNS(Ubl::CBC_NS, 'cbc:InstructionNote', $instruction)
            );
        }

        $dom->documentElement->appendChild($paymentMeans);
    }

    /**
     * Add simple element to root.
     */
    private function addElement(DOMDocument $dom, string $name, string $value): DOMElement
    {
        [$prefix, $localName] = explode(':', $name);
        $ns = $prefix === 'cbc' ? Ubl::CBC_NS : Ubl::CAC_NS;

        $element = $dom->createElementNS($ns, $name, htmlspecialchars($value, ENT_XML1));
        $dom->documentElement->appendChild($element);

        return $element;
    }
}
