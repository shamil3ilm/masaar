<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Document;

use App\Domains\Compliance\Countries\SaudiArabia\DTOs\AddressData;
use App\Domains\Compliance\Countries\SaudiArabia\DTOs\InvoiceXmlData;
use App\Domains\Compliance\Countries\SaudiArabia\DTOs\QrCodeData;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraConfig;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\InvoiceValidator;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\CertificateService;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\EcdsaSigner;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\InvoiceHasher;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\QrCodeGenerator;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\QrCodeInjector;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\XadesSigner;
use App\Domains\Compliance\Countries\SaudiArabia\Support\FatooraTime;
use App\Domains\Compliance\Countries\SaudiArabia\Support\TextNormalizer;
use App\Domains\Invoice\Enums\DocumentType;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Models\InvoiceCharge;
use App\Domains\Invoice\Models\InvoiceLine;
use App\Domains\Invoice\Models\InvoicePrepayment;
use App\Domains\Invoice\Support\UnitCode;
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
            sellerName: $this->normalizeName($organization->documentName()),
            vatNumber: $organization->vat_number ?? '',
            timestamp: $this->issuedAt($invoice),
            // What is to be paid: on a final invoice settling prepayments,
            // the total less them, as ZATCA's own advance-payment sample
            // carries tag 4 (1035 of a 2185 total with 1150 prepaid).
            invoiceTotal: number_format((float) $invoice->payable(), 2, '.', ''),
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
            'description' => $line->documentDescription(),
            'itemClassificationCode' => $line->class_code,
            'quantity' => (float) $line->quantity,
            'unitCode' => UnitCode::normalise($line->unit_code),
            'unitPrice' => (float) $line->unit_price,
            // The exact net, where the line has one. A net price backed out
            // of a VAT-inclusive one is rounded; this is not.
            'lineNet' => $line->net_amount !== null ? (float) $line->net_amount : null,
            // The line's own discount, already off its net, stated as its
            // allowance (BT-136). Not on an invoice priced with VAT in it:
            // that discount is gross, and the net price the document states
            // already has it taken off.
            'discount' => $invoice->prices_include_vat ? 0.0 : (float) ($line->discount_amount ?? 0),
            'charges' => $this->lineCharges($invoice, $line),
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
            issueTime: FatooraTime::toSaudiTime($invoice->issuedMoment())->format('H:i:s'),
            invoiceTypeCode: $documentType->getTypeCode(),
            invoiceSubtype: $invoiceSubtype,
            currency: $invoice->currency,
            sellerName: $this->normalizeName($organization->documentName()),
            sellerVatNumber: $organization->vat_number ?? '',
            sellerAddress: $organization->getAddressData(),
            // Null rather than an empty string for a walk-in sale, so the
            // builder omits the name instead of writing an empty one.
            buyerName: $invoice->documentBuyerName() === ''
                ? null
                : $this->normalizeName($invoice->documentBuyerName()),
            subtotal: (float) $invoice->subtotal,
            taxAmount: (float) $invoice->tax_amount,
            total: (float) $invoice->total,
            lines: $lines,
            supplyDate: $invoice->supply_date?->format('Y-m-d'),
            supplyEndDate: $invoice->supply_end_date?->format('Y-m-d'),
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
            // BT-113, what the prepayments settled came to.
            prepaidAmount: (float) ($invoice->prepaid_amount ?? 0),
            paymentMeansCode: $invoice->payment_means_code ?? '10',
            // KSA-22. InvoiceXmlData has always had a place for the terms,
            // and nothing filled it.
            paymentTerms: $this->filledOrNull($invoice->payment_terms),
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
            isGovernment: (bool) ($invoice->is_government ?? false),
            // Prices entered with VAT in them: the stored VAT is the figure
            // that keeps the customer's total exact, and the document
            // declares it rather than recomputing it.
            isTaxInclusive: (bool) $invoice->prices_include_vat,
            charges: $this->documentCharges($invoice),
            prepayments: $this->prepayments($invoice),
            orderReference: $this->filledOrNull($invoice->order_reference),
            contractReference: $this->filledOrNull($invoice->contract_reference),
        );
    }

    /**
     * The charges on the whole invoice (BG-21) as the document states them:
     * each at its net. A percentage and its base are stated only on an
     * invoice priced without VAT, where the net is the percentage of the
     * base (BR-KSA-EN16931-03); with VAT in the prices both are gross, and
     * the net alone is stated, as BR-KSA-EN16931-04 and -05 allow.
     *
     * @return list<array<string, mixed>>
     */
    private function documentCharges(Invoice $invoice): array
    {
        $inclusive = (bool) $invoice->prices_include_vat;

        return $invoice->charges->map(fn (InvoiceCharge $charge): array => [
            'amount' => (float) $charge->net_amount,
            'percent' => $inclusive || $charge->percent === null ? null : (float) $charge->percent,
            'base' => $inclusive || $charge->base_amount === null ? null : (float) $charge->base_amount,
            'reasonCode' => $charge->reason_code,
            'reason' => $charge->reason,
            'taxCategory' => (string) ($charge->tax_category ?: FatooraConfig::TAX_CATEGORY_STANDARD),
            'taxRate' => (float) ($charge->tax_rate ?? 0),
            'exemptionCode' => $charge->exempt_code,
            'exemptionReason' => $charge->exempt_reason,
        ])->values()->all();
    }

    /**
     * The prepayments a final invoice settles, as the document names each:
     * the prepayment invoice's number, UUID, issue date and its issue time on
     * the Kingdom's clock (KSA-26 to KSA-29), and what was prepaid at each
     * category (KSA-31 to KSA-34), with the exemption where it is not
     * standard-rated (KSA-41, KSA-42).
     *
     * @return list<array<string, mixed>>
     */
    private function prepayments(Invoice $invoice): array
    {
        return $invoice->prepayments->map(fn (InvoicePrepayment $prepayment): array => [
            'number' => $prepayment->invoice_number,
            'uuid' => $prepayment->prepayment_invoice_id,
            'issueDate' => $prepayment->issue_date->format('Y-m-d'),
            'issueTime' => FatooraTime::toSaudiTime($prepayment->issued_at)->format('H:i:s'),
            'taxCategory' => $prepayment->tax_category,
            'taxRate' => (float) $prepayment->tax_rate,
            'exemptionCode' => $prepayment->exempt_code,
            'exemptionReason' => $prepayment->exempt_reason,
            'taxable' => (float) $prepayment->taxable_amount,
            'tax' => (float) $prepayment->tax_amount,
        ])->values()->all();
    }

    /**
     * A line's own charges (BG-28), on an invoice priced without VAT: each
     * as an amount, with its percentage and base when it is one. With VAT in
     * the prices they are in the line's net and its stated price instead.
     *
     * @return list<array<string, mixed>>
     */
    private function lineCharges(Invoice $invoice, InvoiceLine $line): array
    {
        if ($invoice->prices_include_vat) {
            return [];
        }

        return $line->charges->map(fn (InvoiceCharge $charge): array => [
            'amount' => (float) $charge->amount,
            'percent' => $charge->percent === null ? null : (float) $charge->percent,
            'base' => $charge->base_amount === null ? null : (float) $charge->base_amount,
            'reasonCode' => $charge->reason_code,
            'reason' => $charge->reason,
        ])->values()->all();
    }

    /** Trimmed, or null for nothing: an empty reference element is no reference. */
    private function filledOrNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
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
            .'T'.FatooraTime::toSaudiTime($invoice->issuedMoment())->format('H:i:s');
    }
}
