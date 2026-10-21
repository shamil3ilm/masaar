<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Onboarding;

use App\Domains\Compliance\Countries\SaudiArabia\Document\XmlBuilder;
use App\Domains\Compliance\Countries\SaudiArabia\DTOs\AddressData;
use App\Domains\Compliance\Countries\SaudiArabia\DTOs\InvoiceXmlData;
use App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraConfig;
use App\Domains\Compliance\Countries\SaudiArabia\Signing\InvoiceHasher;
use App\Domains\Compliance\Countries\SaudiArabia\Support\FatooraTime;
use App\Domains\Invoice\Support\UnitCode;
use Closure;
use Illuminate\Support\Str;

/**
 * The six documents ZATCA's compliance check requires, as one hash chain.
 *
 * Before it issues a production CSID the authority wants a standard and a
 * simplified invoice, credit note and debit note from the device. The
 * organization endpoint, the branch endpoint and fatoora:onboard each built
 * these separately, and the copies had drifted apart on where the chain starts.
 *
 * Each document's PIH is the hash of the previous document as it is submitted.
 * A caller that signs before submitting passes a finalize step, and the chain
 * follows the signed bytes rather than the unsigned ones.
 */
final class ComplianceSampleSet
{
    /**
     * Document key => [invoice type code, sub-type, note kind].
     */
    public const DOCUMENTS = [
        'standard_invoice' => ['388', '01', null],
        'standard_credit_note' => ['381', '01', 'credit'],
        'standard_debit_note' => ['383', '01', 'debit'],
        'simplified_invoice' => ['388', '02', null],
        'simplified_credit_note' => ['381', '02', 'credit'],
        'simplified_debit_note' => ['383', '02', 'debit'],
    ];

    public function __construct(
        private readonly XmlBuilder $xmlBuilder,
        private readonly InvoiceHasher $hasher,
    ) {}

    /**
     * @param  (Closure(string, InvoiceXmlData): string)|null  $finalize  turns the built XML into what is submitted
     * @return array<string, array{data: InvoiceXmlData, xml: string, hash: string}>
     */
    public function build(
        string $sellerName,
        string $sellerVatNumber,
        ?string $sellerCrNumber,
        AddressData $sellerAddress,
        string $numberPrefix,
        ?Closure $finalize = null,
        string $sellerIdScheme = 'CRN',
    ): array {
        $documents = [];
        $previousHash = FatooraConfig::GENESIS_PIH;
        $icv = 0;

        foreach (self::DOCUMENTS as $key => [$typeCode, $subtype, $note]) {
            $icv++;

            $data = $this->document($typeCode, $subtype, $note, $icv, $previousHash, $sellerName, $sellerVatNumber, $sellerCrNumber, $sellerAddress, $numberPrefix, $sellerIdScheme);
            $xml = $this->xmlBuilder->build($data);

            if ($finalize !== null) {
                $xml = $finalize($xml, $data);
            }

            // The invoice hash, not a hash of the bytes: canonicalized, with the
            // extensions, signature and QR taken out. The compliance endpoint
            // follows the chain with it.
            $previousHash = $this->hasher->hash($xml);

            $documents[$key] = ['data' => $data, 'xml' => $xml, 'hash' => $previousHash];
        }

        return $documents;
    }

    private function document(
        string $typeCode,
        string $subtype,
        ?string $note,
        int $icv,
        string $previousHash,
        string $sellerName,
        string $sellerVatNumber,
        ?string $sellerCrNumber,
        AddressData $sellerAddress,
        string $numberPrefix,
        string $sellerIdScheme,
    ): InvoiceXmlData {
        $isStandard = $subtype === '01';

        // One reading of the Kingdom's clock, used for every date and time
        // below. The Kingdom's because these are civil statements about when
        // the document was issued, and the documents this platform really
        // sends state them that way - a sample that disagrees is not a sample
        // of anything. One reading because three separate calls a hundredth
        // of a second before midnight would date the document one day and
        // time it the next.
        $issuedAt = FatooraTime::saudiNow();

        return new InvoiceXmlData(
            uuid: (string) Str::uuid(),
            invoiceNumber: "{$numberPrefix}-{$icv}",
            icv: $icv,
            issueDate: $issuedAt->format('Y-m-d'),
            issueTime: $issuedAt->format('H:i:s'),
            invoiceTypeCode: $typeCode,
            invoiceSubtype: $subtype,
            currency: 'SAR',
            sellerName: $sellerName,
            sellerVatNumber: $sellerVatNumber,
            sellerAddress: $sellerAddress,
            buyerName: $isStandard ? 'Test Buyer Company' : 'Cash Customer',
            subtotal: 100.00,
            taxAmount: 15.00,
            total: 115.00,
            lines: [[
                'description' => 'Test Product',
                'quantity' => 1.0,
                'unitPrice' => 100.00,
                'taxRate' => 15.0,
                'taxAmount' => 15.00,
                'lineTotal' => 100.00,
                'taxCategory' => 'S',
                'unitCode' => UnitCode::DEFAULT,
            ]],
            // A standard invoice is B2B: it carries the supply date and the
            // buyer's VAT number and address, which a simplified one does not.
            supplyDate: $isStandard ? $issuedAt->format('Y-m-d') : null,
            sellerCrNumber: $sellerCrNumber,
            sellerIdScheme: $sellerIdScheme,
            buyerVatNumber: $isStandard ? '399999999800003' : null,
            buyerAddress: $isStandard ? new AddressData(
                street: 'Prince Sultan Road',
                buildingNumber: '5678',
                district: 'Al Malaz',
                city: 'Riyadh',
                postalCode: '54321',
                countryCode: 'SA',
            ) : null,
            paymentMeansCode: '10',
            previousInvoiceHash: $previousHash,
            // A credit or debit note references the invoice it adjusts and says
            // why (BR-KSA-17).
            billingReferenceId: $note === null ? null : 'INV-REF-001',
            creditDebitReason: match ($note) {
                'credit' => 'Return of goods',
                'debit' => 'Price adjustment',
                default => null,
            },
        );
    }
}
