<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Domains\Compliance\Fatoora\Config\FatooraConfig;
use App\Domains\Compliance\Fatoora\DTOs\AddressData;
use App\Domains\Compliance\Fatoora\DTOs\InvoiceXmlData;
use App\Domains\Compliance\Fatoora\DTOs\QrCodeData;
use App\Domains\Compliance\Fatoora\Helpers\FatooraTime;
use App\Domains\Compliance\Fatoora\Helpers\TextNormalizer;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;

/**
 * Main ZATCA compliance service.
 *
 * Orchestrates XML generation, hashing, signing, and QR code creation.
 * This is the primary service for preparing invoices for ZATCA submission.
 */
class DocumentBuilder
{
    public function __construct(
        private readonly XmlBuilder $xmlBuilder,
        private readonly InvoiceHasher $hasher,
        private readonly QrCodeGenerator $qrGenerator,
        private readonly EcdsaSigner $ecdsaSigner,
        private readonly XadesSigner $xadesSigner,
        private readonly CertificateService $certificateService,
        private readonly InvoiceValidator $validator,
        private readonly QrCodeInjector $qrInjector,
    ) {}

    /**
     * Generate complete compliance data for an invoice.
     *
     * @return array{xml: string, hash: string, qr_code: string, signed_xml: ?string}
     */
    public function generateComplianceData(
        Invoice $invoice,
        Organization $organization,
        ?string $previousInvoiceHash = null,
        ?string $privateKey = null,
        ?string $certificate = null,
    ): array {
        // Build XML data from invoice
        $xmlData = $this->buildXmlData($invoice, $organization, $previousInvoiceHash);

        // Generate unsigned XML
        $xml = $this->xmlBuilder->build($xmlData);

        // Generate invoice hash
        $hash = $this->hasher->hash($xml);

        // Determine if we can sign (have certificate)
        $canSign = $privateKey !== null && $certificate !== null;
        $signedXml = null;
        $signature = null;
        $publicKey = null;
        $certSignature = null;

        if ($canSign) {
            // Sign the XML
            $signedXml = $this->xadesSigner->sign($xml, $privateKey, $certificate);

            // Extract signature for QR code
            $signature = $this->xadesSigner->extractSignature($signedXml);

            // Get public key for QR
            $publicKey = $this->ecdsaSigner->getPublicKeyBytes(
                $this->ecdsaSigner->extractPublicKey($certificate)
            );

            // Get certificate signature for QR
            $certSignature = $this->certificateService->getCertificateSignature($certificate);

            // Update hash from signed XML
            $hash = $this->hasher->hash($signedXml);
        }

        // Generate QR code
        // ZATCA TLV encoding requires raw bytes for tags 6-9, not base64
        // Tags 6 and 7 carry base64 text; tags 8 and 9 carry raw DER.
        //
        // It is an odd split and it is ZATCA's: the hash and the signature go
        // in as the base64 strings they already are, while the public key and
        // the certificate signature go in as bytes. Decoding all four made a
        // QR the authority refuses — verified against the QR its own SDK
        // generates for the same document, which agrees tag for tag.
        $qrData = new QrCodeData(
            // Same normalisation as the XML: TLV tag 1 and the XML seller name
            // must carry identical bytes.
            sellerName: $this->normalizeName($organization->name),
            vatNumber: $organization->vat_number ?? '',
            timestamp: $this->issuedAt($invoice),
            invoiceTotal: number_format((float) $invoice->total, 2, '.', ''),
            vatTotal: number_format((float) $invoice->tax_amount, 2, '.', ''),
            invoiceHash: $hash,
            signature: $signature,
            publicKey: $publicKey !== null ? base64_decode($publicKey) : null,
            certificateSignature: $certSignature, // already raw bytes
        );

        // The full QR whenever there is a signature to put in it.
        //
        // Not chosen by document type. BR-KSA-60:
        // "Cryptographic stamp (KSA-15) must exist in simplified tax invoices
        // and associated credit notes and debit notes." A simplified invoice
        // is reported after the fact and reaches the customer first, so its QR
        // is the only thing standing behind it; a standard one is cleared
        // before it is handed over. The rule binds on the one that was getting
        // five tags.
        //
        // Type is the wrong question either way. ZATCA's own standard sample
        // carries the cryptographic tags too, and what decides whether they
        // can be written is whether this organization has credentials —
        // generatePhase2() refuses without them, and an unsigned invoice has
        // nothing to put in tags 6 to 9.
        $qrCode = $canSign
            ? $this->qrGenerator->generatePhase2($qrData)
            : $this->qrGenerator->generatePhase1($qrData);

        // Put the QR in, if there is a signature to sit beside. The injector
        // owns the load and the save, because that round trip is where a
        // signature gets damaged - see its docblock.
        if ($signedXml !== null) {
            $signedXml = $this->qrInjector->inject($signedXml, $qrCode);
        }

        return [
            'xml' => $canSign ? $signedXml : $xml,
            'hash' => $hash,
            'qr_code' => $qrCode,
            'signed_xml' => $signedXml,
        ];
    }

    /**
     * Normalise a party name for the invoice XML.
     *
     * Arabic text reaches ZATCA in several visually identical encodings —
     * alef variants, presentation forms, embedded bidi marks. Two spellings of
     * the same taxpayer name would otherwise produce different bytes, and the
     * bytes are what gets hashed and signed.
     *
     * Controlled by fatoora.features.arabic_normalization.
     */
    private function normalizeName(string $name): string
    {
        if (! config('fatoora.features.arabic_normalization', true)) {
            return $name;
        }

        return TextNormalizer::normalizeName($name);
    }

    /**
     * Validate invoice before submission.
     *
     * @return array{valid: bool, errors: array, warnings: array}
     */
    public function validateInvoice(Invoice $invoice, Organization $organization): array
    {
        return $this->validator->validate($invoice, $organization);
    }

    /**
     * Build XML data structure from invoice.
     */
    private function buildXmlData(
        Invoice $invoice,
        Organization $organization,
        ?string $previousInvoiceHash,
    ): InvoiceXmlData {
        // Get document type
        $documentType = $invoice->document_type ?? DocumentType::Invoice;

        // Get invoice subtype (01=B2B, 02=B2C)
        $invoiceSubtype = $invoice->type->value === 'standard' ? '01' : '02';

        // Format invoice lines with full tax category data from database
        $lines = $invoice->lines->map(fn ($line) => [
            'description' => $line->description,
            'itemClassificationCode' => $line->class_code,
            'quantity' => (float) $line->quantity,
            'unitCode' => $line->unit_code ?? 'PCE',
            'unitPrice' => (float) $line->unit_price,
            'taxRate' => (float) $line->tax_rate,
            'taxAmount' => (float) $line->tax_amount,
            'lineTotal' => (float) $line->line_total,
            // Use stored tax category, fallback to computed value
            'taxCategory' => $line->tax_category ?? $this->getTaxCategory(
                (float) $line->tax_rate,
                $line->exempt_code
            ),
            'taxExemptionReasonCode' => $line->exempt_code,
            'taxExemptionReason' => $line->exempt_reason,
        ])->toArray();

        return new InvoiceXmlData(
            uuid: $invoice->id,
            invoiceNumber: $invoice->invoice_number,
            icv: (int) $invoice->icv,
            issueDate: $invoice->issue_date->format('Y-m-d'),
            // The document's own civil time, which is the Kingdom's and not the
            // application's. created_at is held in UTC, and the date beside
            // this is the business date, so reading the time off the one and
            // the date off the other puts them on different clocks: an
            // invoice issued at 01:30 in Riyadh went out carrying 22:30 of
            // the same date, a time twenty-one hours from when it was
            // issued, and so did every invoice issued before 03:00.
            issueTime: FatooraTime::toSaudiTime($invoice->created_at)->format('H:i:s'),
            invoiceTypeCode: $documentType->getTypeCode(),
            invoiceSubtype: $invoiceSubtype,
            currency: $invoice->currency,
            sellerName: $this->normalizeName($organization->name),
            sellerVatNumber: $organization->vat_number ?? '',
            sellerAddress: $organization->getAddressData(),
            buyerName: $this->normalizeName((string) $invoice->buyer_name),
            subtotal: (float) $invoice->subtotal,
            taxAmount: (float) $invoice->tax_amount,
            total: (float) $invoice->total,
            lines: $lines,
            supplyDate: $invoice->supply_date?->format('Y-m-d'),
            sellerCrNumber: $organization->cr_number,
            buyerVatNumber: $invoice->buyer_vat_number,
            // BT-46 and BT-46-1. XmlBuilder has always emitted these, and
            // InvoiceXmlData has always carried them, and nothing ever set
            // them — so buyerHasAlternativeId() was false for every invoice
            // and no buyer without a VAT number could be identified at all.
            // BR-KSA-49 needs a NAT identifier on healthcare and education
            // supplies, which made those unfileable.
            buyerId: $invoice->buyer_id,
            buyerIdScheme: $invoice->buyer_id_scheme,
            buyerAddress: $invoice->buyer_address ? AddressData::fromArray($invoice->buyer_address) : null,
            discount: (float) ($invoice->discount_amount ?? 0),
            paymentMeansCode: $invoice->payment_means_code ?? '10',
            previousInvoiceHash: $previousInvoiceHash,
            billingReferenceId: $invoice->billing_ref,
            // Without the rate there is nothing to convert the tax with, and
            // the document falls back to declaring Saudi VAT in a foreign
            // currency.
            exchangeRate: $invoice->exchange_rate !== null ? (float) $invoice->exchange_rate : null,
            // BR-KSA-17: a credit or debit note has to say why. The API
            // collects the reason and stores it on the invoice; this is the
            // step that carries it into the document.
            creditDebitReason: $invoice->adjustment_reason,
            // Invoice type sub-flags (bits 3-7 per ZATCA specification)
            isThirdParty: (bool) ($invoice->is_third_party ?? false),
            isNominal: (bool) ($invoice->is_nominal ?? false),
            isExport: (bool) ($invoice->is_export ?? false),
            isSummary: (bool) ($invoice->is_summary ?? false),
            isSelfBilled: (bool) ($invoice->is_self_billed ?? false),
        );
    }

    /**
     * Get tax category code from rate.
     *
     * ZATCA Tax Categories:
     * - S = Standard rated (any positive VAT rate: 5%, 15%, etc.)
     * - Z = Zero rated (0% with exemption reason code)
     * - E = Exempt (not subject to VAT with exemption reason)
     * - O = Out of scope (services outside KSA)
     *
     * @param  float  $taxRate  Tax rate percentage
     * @param  string|null  $exemptionCode  Optional exemption reason code
     * @return string Tax category code
     */
    private function getTaxCategory(float $taxRate, ?string $exemptionCode = null): string
    {
        return FatooraConfig::taxCategoryFor($exemptionCode, $taxRate);
    }

    /**
     * The instant the invoice says it was issued, as the QR states it.
     *
     * The XML declares this as two fields — IssueDate from issue_date and
     * IssueTime from created_at — and ZATCA compares the QR's tag 3 against
     * them, rejecting a document whose QR disagrees with its own header.
     *
     * Built from the same two values the header carries, not from issue_date
     * alone: that column is a date, so on its own it yields midnight while the
     * header's IssueTime says something else.
     *
     * No trailing Z. The comparison is textual — IssueDate, a T, IssueTime —
     * and ZATCA's own sample invoices carry tag 3 exactly that way. A Z is a
     * correct thing to write and it makes the two strings differ, which is the
     * only thing being checked.
     *
     * On the Kingdom's clock, like the two fields it is compared against.
     * The date here is the business date and the time was read off created_at,
     * which is held in UTC, so the two sat on different clocks: an invoice
     * issued at 01:30 in Riyadh showed 22:30 of the same date in its QR, and a
     * consumer scanning it saw a time twenty-one hours away. The three
     * statements a document makes about when it was issued - IssueDate,
     * IssueTime and this - have to agree, so all three read the Kingdom's
     * clock.
     */
    private function issuedAt(Invoice $invoice): string
    {
        return $invoice->issue_date->format('Y-m-d')
            .'T'.FatooraTime::toSaudiTime($invoice->created_at)->format('H:i:s');
    }
}
