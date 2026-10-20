<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Document;

use App\Domains\Compliance\Countries\UnitedArabEmirates\DTOs\PintAddress;
use App\Domains\Compliance\Countries\UnitedArabEmirates\DTOs\PintAllowance;
use App\Domains\Compliance\Countries\UnitedArabEmirates\DTOs\PintCharge;
use App\Domains\Compliance\Countries\UnitedArabEmirates\DTOs\PintDocument;
use App\Domains\Compliance\Countries\UnitedArabEmirates\DTOs\PintLine;
use App\Domains\Compliance\Countries\UnitedArabEmirates\DTOs\PintParty;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Enums\PintTaxCategory;
use DOMDocument;
use DOMElement;

/**
 * A PINT document as UBL 2.1: an Invoice or a CreditNote.
 *
 * Element order is the UBL schema's, which is not the same for the two
 * roots - a credit note has no DueDate, states its reason
 * (DiscrepancyResponse) after the period, and puts the exchange rate before
 * the allowances rather than after. The order follows the PINT AE syntax
 * pages, and the conformance test checks the result against the UBL 2.1
 * schemas as well as the schematrons.
 *
 * Values go in as DOM text nodes, so a name with an ampersand is escaped by
 * the DOM and never by hand. Nothing is written for an absent value: an
 * empty element is itself an error (IBR-079).
 *
 * @see https://docs.peppol.eu/poac/ae/pint-ae/trn-invoice/syntax/
 * @see https://docs.peppol.eu/poac/ae/pint-ae/trn-creditnote/syntax/
 */
class PintUblBuilder
{
    private const ROOT = 'urn:oasis:names:specification:ubl:schema:xsd:';

    private const CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    private const CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    public function build(PintDocument $document): string
    {
        $credit = $document->category->isCreditNote();
        $name = $credit ? 'CreditNote' : 'Invoice';

        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;
        $root = $xml->createElementNS(self::ROOT.$name.'-2', $name);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', self::CAC);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', self::CBC);
        $xml->appendChild($root);

        $this->header($root, $document, $credit);
        $this->party($this->add($root, 'cac:AccountingSupplierParty'), $document->seller, true);
        $this->party($this->add($root, 'cac:AccountingCustomerParty'), $document->buyer, false);
        $this->identifiedParty($root, 'cac:BuyerCustomerParty', $document->beneficiaryId);
        $this->identifiedParty($root, 'cac:SellerSupplierParty', $document->principalId);

        if ($document->delivery !== null) {
            $this->address($this->add($this->add($this->add($root, 'cac:Delivery'), 'cac:DeliveryLocation'), 'cac:Address'), $document->delivery);
        }

        $this->paymentMeans($root, $document);

        // IBT-020, once (IBR-SR-05), after the payment means on either root.
        if ($document->paymentTerms !== null) {
            $this->add($this->add($root, 'cac:PaymentTerms'), 'cbc:Note', $document->paymentTerms);
        }

        // The two roots disagree on where the exchange rate goes.
        if ($credit) {
            $this->exchangeRate($root, $document);
            $this->allowances($root, $document);
        } else {
            $this->allowances($root, $document);
            $this->exchangeRate($root, $document);
        }

        $this->taxTotals($root, $document);
        $this->monetaryTotal($root, $document);

        foreach ($document->lines as $line) {
            $this->line($root, $line, $document->currency, $credit);
        }

        return (string) $xml->saveXML();
    }

    private function header(DOMElement $root, PintDocument $d, bool $credit): void
    {
        $this->add($root, 'cbc:CustomizationID', $d->customizationId);
        $this->add($root, 'cbc:ProfileID', $d->profileId);
        $this->add($root, 'cbc:ProfileExecutionID', $d->transactionFlags);
        $this->add($root, 'cbc:ID', $d->number);
        $this->add($root, 'cbc:UUID', $d->uuid);
        $this->add($root, 'cbc:IssueDate', $d->issueDate);

        if ($credit) {
            $this->add($root, 'cbc:CreditNoteTypeCode', $d->typeCode);
            $this->add($root, 'cbc:Note', $d->note);
        } else {
            $this->add($root, 'cbc:DueDate', $d->dueDate);
            $this->add($root, 'cbc:InvoiceTypeCode', $d->typeCode);
            // IBT-022, where the billing frequency is Others (IBR-160-AE).
            $this->add($root, 'cbc:Note', $d->note);
            $this->add($root, 'cbc:TaxPointDate', $d->taxPointDate);
        }

        $this->add($root, 'cbc:DocumentCurrencyCode', $d->currency);
        $this->add($root, 'cbc:TaxCurrencyCode', $d->taxCurrency);
        // IBT-010, the buyer's own reference.
        $this->add($root, 'cbc:BuyerReference', $d->buyerReference);

        if ($d->periodStart !== null || $d->periodEnd !== null) {
            $period = $this->add($root, 'cac:InvoicePeriod');
            $this->add($period, 'cbc:StartDate', $d->periodStart);
            $this->add($period, 'cbc:EndDate', $d->periodEnd);
            // BTAE-06, after the dates as UBL's PeriodType orders it.
            $this->add($period, 'cbc:DescriptionCode', $d->billingFrequency);
        }

        if ($credit && $d->creditReason !== null) {
            $this->add($this->add($root, 'cac:DiscrepancyResponse'), 'cbc:ResponseCode', $d->creditReason);
        }

        // IBT-013, the purchase order.
        if ($d->orderReference !== null) {
            $this->add($this->add($root, 'cac:OrderReference'), 'cbc:ID', $d->orderReference);
        }

        foreach ($d->billingReferences as $reference) {
            $this->add($this->add($this->add($root, 'cac:BillingReference'), 'cac:InvoiceDocumentReference'), 'cbc:ID', $reference);
        }

        // IBT-012, the contract, at most once (IBR-094).
        if ($d->contractReference !== null) {
            $this->add($this->add($root, 'cac:ContractDocumentReference'), 'cbc:ID', $d->contractReference);
        }

        // BTAE-20: the total with VAT in dirhams, for a document that is not.
        if ($d->totalInTaxCurrency !== null) {
            $reference = $this->add($root, 'cac:AdditionalDocumentReference');
            $this->add($reference, 'cbc:ID', $d->taxCurrency);
            $this->add($reference, 'cbc:DocumentTypeCode', 'aedtotal-incl-vat');
            $this->add($reference, 'cbc:DocumentDescription', $d->totalInTaxCurrency);
        }
    }

    private function party(DOMElement $parent, PintParty $p, bool $seller): void
    {
        $party = $this->add($parent, 'cac:Party');
        $this->add($party, 'cbc:EndpointID', $p->endpointId, ['schemeID' => $p->endpointScheme]);
        $this->address($this->add($party, 'cac:PostalAddress'), $p->address);

        if ($p->vatId !== null) {
            $this->taxScheme($party, $p->vatId, 'VAT');
        }

        // IBT-032: a seller with no TRN is identified by its TIN, under a
        // scheme other than VAT (IBR-148-AE), as the specification's own
        // Seller-TIN-identifier example does.
        if ($seller && $p->taxRegistrationId !== null) {
            $this->taxScheme($party, $p->taxRegistrationId, 'TIN');
        }

        $legal = $this->add($party, 'cac:PartyLegalEntity');
        $this->add($legal, 'cbc:RegistrationName', $p->name);
        $this->add($legal, 'cbc:CompanyID', $p->legalId, array_filter([
            'schemeAgencyID' => $p->legalType,
            'schemeAgencyName' => $p->legalAuthority,
        ], fn (?string $v): bool => $v !== null));
    }

    private function taxScheme(DOMElement $party, string $id, string $scheme): void
    {
        $node = $this->add($party, 'cac:PartyTaxScheme');
        $this->add($node, 'cbc:CompanyID', $id);
        $this->add($this->add($node, 'cac:TaxScheme'), 'cbc:ID', $scheme);
    }

    /** The beneficiary (BTAE-01) or the principal (BTAE-14): a party known only by an identifier. */
    private function identifiedParty(DOMElement $root, string $element, ?string $id): void
    {
        if ($id !== null) {
            $this->add($this->add($this->add($this->add($root, $element), 'cac:Party'), 'cac:PartyIdentification'), 'cbc:ID', $id);
        }
    }

    private function address(DOMElement $node, PintAddress $a): void
    {
        $this->add($node, 'cbc:StreetName', $a->street);
        $this->add($node, 'cbc:AdditionalStreetName', $a->additionalStreet);
        $this->add($node, 'cbc:CityName', $a->city);
        $this->add($node, 'cbc:PostalZone', $a->postalCode);
        $this->add($node, 'cbc:CountrySubentity', $a->subdivision);
        $this->add($this->add($node, 'cac:Country'), 'cbc:IdentificationCode', $a->countryCode);
    }

    private function paymentMeans(DOMElement $root, PintDocument $d): void
    {
        if ($d->paymentMeansCode === null) {
            return;
        }

        $means = $this->add($root, 'cac:PaymentMeans');
        $this->add($means, 'cbc:PaymentMeansCode', $d->paymentMeansCode);

        if ($d->paymentAccount !== null) {
            $this->add($this->add($means, 'cac:PayeeFinancialAccount'), 'cbc:ID', $d->paymentAccount);
        }
    }

    private function allowances(DOMElement $root, PintDocument $d): void
    {
        foreach ($d->allowances as $allowance) {
            $node = $this->add($root, 'cac:AllowanceCharge');
            $this->add($node, 'cbc:ChargeIndicator', 'false');
            // 95 is UNCL5189's "Discount".
            $this->add($node, 'cbc:AllowanceChargeReasonCode', '95');
            $this->add($node, 'cbc:AllowanceChargeReason', 'Discount');
            $this->add($node, 'cbc:Amount', $allowance->amount, ['currencyID' => $d->currency]);
            $this->taxCategory($node, 'cac:TaxCategory', $allowance->category, $allowance->rate, $this->allowanceExemption($allowance));
        }

        // IBG-21, each with its category (ALIGNED-IBRP-037) and, when exempt,
        // its exemption (IBR-169-AE). The rate as an allowance states it: a
        // zero for zero-rated and exempt (ALIGNED-IBRP-Z-07, -E-07), none out
        // of scope (ALIGNED-IBRP-O-07).
        foreach ($d->charges as $charge) {
            $node = $this->charge($root, $charge, $d->currency);
            $rate = match ($charge->category) {
                PintTaxCategory::Standard => $charge->rate,
                PintTaxCategory::OutOfScope => null,
                default => '0',
            };
            $this->taxCategory($node, 'cac:TaxCategory', $charge->category, $rate, $charge->exemptionCode);
        }
    }

    /**
     * A charge, on the document (IBG-21) or a line (IBG-28): the indicator,
     * its UNCL 7161 code (IBR-CL-20) and reason, then the percentage, amount
     * and base - the percentage and base together (ALIGNED-IBRP-058) and
     * their product the amount (IBR-146-AE).
     */
    private function charge(DOMElement $parent, PintCharge $charge, string $currency): DOMElement
    {
        $node = $this->add($parent, 'cac:AllowanceCharge');
        $this->add($node, 'cbc:ChargeIndicator', 'true');
        $this->add($node, 'cbc:AllowanceChargeReasonCode', $charge->reasonCode);
        $this->add($node, 'cbc:AllowanceChargeReason', $charge->reason);
        $percentage = $charge->percent !== null && $charge->base !== null;
        $this->add($node, 'cbc:MultiplierFactorNumeric', $percentage ? $charge->percent : null);
        $this->add($node, 'cbc:Amount', $charge->amount, ['currencyID' => $currency]);
        $this->add($node, 'cbc:BaseAmount', $percentage ? $charge->base : null, ['currencyID' => $currency]);

        return $node;
    }

    /** IBR-168-AE: an exempt allowance names its exemption. */
    private function allowanceExemption(PintAllowance $allowance): ?string
    {
        return $allowance->category === PintTaxCategory::Exempt ? $allowance->exemptionCode : null;
    }

    private function exchangeRate(DOMElement $root, PintDocument $d): void
    {
        if ($d->taxCurrency === null) {
            return;
        }

        $rate = $this->add($root, 'cac:TaxExchangeRate');
        $this->add($rate, 'cbc:SourceCurrencyCode', $d->currency);
        $this->add($rate, 'cbc:TargetCurrencyCode', $d->taxCurrency);
        $this->add($rate, 'cbc:CalculationRate', $d->exchangeRate);
    }

    private function taxTotals(DOMElement $root, PintDocument $d): void
    {
        $total = $this->add($root, 'cac:TaxTotal');
        $this->add($total, 'cbc:TaxAmount', $d->taxAmount, ['currencyID' => $d->currency]);

        foreach ($d->subtotals as $subtotal) {
            $node = $this->add($total, 'cac:TaxSubtotal');
            $this->add($node, 'cbc:TaxableAmount', $subtotal->taxableAmount, ['currencyID' => $d->currency]);
            $this->add($node, 'cbc:TaxAmount', $subtotal->taxAmount, ['currencyID' => $d->currency]);
            $this->taxCategory($node, 'cac:TaxCategory', $subtotal->category, $subtotal->rate, $subtotal->exemptionCode, $subtotal->exemptionReason);
        }

        // IBT-111: the VAT again, in dirhams.
        if ($d->taxCurrency !== null) {
            $this->add($this->add($root, 'cac:TaxTotal'), 'cbc:TaxAmount', $d->taxAmountInTaxCurrency, ['currencyID' => $d->taxCurrency]);
        }
    }

    private function monetaryTotal(DOMElement $root, PintDocument $d): void
    {
        $total = $this->add($root, 'cac:LegalMonetaryTotal');
        $currency = ['currencyID' => $d->currency];
        $this->add($total, 'cbc:LineExtensionAmount', $d->lineExtensionAmount, $currency);
        $this->add($total, 'cbc:TaxExclusiveAmount', $d->taxExclusiveAmount, $currency);
        $this->add($total, 'cbc:TaxInclusiveAmount', $d->taxInclusiveAmount, $currency);

        if ($d->allowances !== []) {
            $this->add($total, 'cbc:AllowanceTotalAmount', $d->allowanceTotal, $currency);
        }

        // IBT-108, after the allowances as UBL orders them.
        if ($d->charges !== []) {
            $this->add($total, 'cbc:ChargeTotalAmount', $d->chargeTotal, $currency);
        }

        $this->add($total, 'cbc:PayableAmount', $d->payableAmount, $currency);
    }

    private function line(DOMElement $root, PintLine $line, string $currency, bool $credit): void
    {
        $node = $this->add($root, $credit ? 'cac:CreditNoteLine' : 'cac:InvoiceLine');
        $this->add($node, 'cbc:ID', $line->id);
        $this->add($node, $credit ? 'cbc:CreditedQuantity' : 'cbc:InvoicedQuantity', $line->quantity, ['unitCode' => $line->unitCode]);
        $this->add($node, 'cbc:LineExtensionAmount', $line->netAmount, ['currencyID' => $currency]);

        // IBG-27, the line's own discount (95 is UNCL5189's "Discount",
        // IBR-CL-19), and IBG-28, its charges: before the item, as UBL
        // orders them.
        if ($line->discount !== null) {
            $discount = $this->add($node, 'cac:AllowanceCharge');
            $this->add($discount, 'cbc:ChargeIndicator', 'false');
            $this->add($discount, 'cbc:AllowanceChargeReasonCode', '95');
            $this->add($discount, 'cbc:AllowanceChargeReason', 'Discount');
            $this->add($discount, 'cbc:Amount', $line->discount, ['currencyID' => $currency]);
        }

        foreach ($line->charges as $charge) {
            $this->charge($node, $charge, $currency);
        }

        $item = $this->add($node, 'cac:Item');
        $this->add($item, 'cbc:Description', $line->description);
        $this->add($item, 'cbc:Name', $line->name);

        // IBR-174-AE: a reverse-charge item is identified by its GTIN (0160).
        if ($line->standardItemId !== null) {
            $this->add($this->add($item, 'cac:StandardItemIdentification'), 'cbc:ID', $line->standardItemId, ['schemeID' => '0160']);
        }

        if ($line->goodsType !== null || $line->classificationCode !== null) {
            $classification = $this->add($item, 'cac:CommodityClassification');
            $this->add($classification, 'cbc:NatureCode', $line->goodsType);
            $this->add($classification, 'cbc:ItemClassificationCode', $line->classificationCode, ['listID' => 'HS']);
        }

        $this->taxCategory($item, 'cac:ClassifiedTaxCategory', $line->category, $line->rate, $line->exemptionCode, $line->exemptionReason);

        // IBR-126-AE wants the gross price and base quantity; there is no
        // price discount, so the gross is the net.
        $price = $this->add($node, 'cac:Price');
        $this->add($price, 'cbc:PriceAmount', $line->price, ['currencyID' => $currency]);
        $this->add($price, 'cbc:BaseQuantity', '1', ['unitCode' => $line->unitCode]);
        $discount = $this->add($price, 'cac:AllowanceCharge');
        $this->add($discount, 'cbc:ChargeIndicator', 'false');
        $this->add($discount, 'cbc:Amount', '0.00', ['currencyID' => $currency]);
        $this->add($discount, 'cbc:BaseAmount', $line->price, ['currencyID' => $currency]);

        // BTAE-10 and BTAE-08, in dirhams whatever the document currency.
        $extension = $this->add($node, 'cac:ItemPriceExtension');
        $this->add($extension, 'cbc:Amount', $line->totalAed, ['currencyID' => 'AED']);

        if ($line->vatAed !== null) {
            $this->add($this->add($extension, 'cac:TaxTotal'), 'cbc:TaxAmount', $line->vatAed, ['currencyID' => 'AED']);
        }
    }

    private function taxCategory(DOMElement $parent, string $element, PintTaxCategory $category, ?string $rate, ?string $exemptionCode = null, ?string $exemptionReason = null): void
    {
        $node = $this->add($parent, $element);
        $this->add($node, 'cbc:ID', $category->value);
        $this->add($node, 'cbc:Percent', $rate);
        $this->add($node, 'cbc:TaxExemptionReasonCode', $exemptionCode);
        $this->add($node, 'cbc:TaxExemptionReason', $exemptionReason);
        $this->add($this->add($node, 'cac:TaxScheme'), 'cbc:ID', 'VAT');
    }

    /**
     * Append an element; with a null value nothing is appended, and the
     * parent is returned so a chain of optional elements stays one line.
     *
     * @param  array<string, string>  $attributes
     */
    private function add(DOMElement $parent, string $qualified, ?string $value = '', array $attributes = []): DOMElement
    {
        if ($value === null) {
            return $parent;
        }

        $namespace = str_starts_with($qualified, 'cac:') ? self::CAC : self::CBC;
        $element = $parent->ownerDocument->createElementNS($namespace, $qualified);

        if ($value !== '') {
            $element->appendChild($parent->ownerDocument->createTextNode($value));
        }

        foreach ($attributes as $name => $attribute) {
            $element->setAttribute($name, $attribute);
        }

        $parent->appendChild($element);

        return $element;
    }
}
