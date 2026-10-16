<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Domains\Compliance\Fatoora\DTOs\InvoiceXmlData;
use App\Domains\Compliance\Fatoora\Support\Ubl;
use App\Domains\Invoice\Services\InvoiceTotals;
use DOMDocument;
use DOMElement;

/**
 * The money an invoice declares at document level: discounts, tax totals and
 * the legal monetary total.
 *
 * Kept together because the allowances and the tax subtotals describe one
 * discount from two directions, ZATCA checks that they agree, and both are
 * read from the one breakdown() here.
 */
class UblTotalsBuilder
{
    /**
     * Allowances, then tax totals, then the legal monetary total.
     */
    public function addTotals(DOMDocument $dom, InvoiceXmlData $data): void
    {
        $this->addAllowanceCharge($dom, $data);
        $this->addTaxTotal($dom, $data);
        $this->addLegalMonetaryTotal($dom, $data);
    }

    /**
     * One allowance element per tax category the discount touches.
     *
     * ZATCA requires cac:AllowanceCharge for a document-level discount, before
     * TaxTotal. BR-S-08 and BR-Z-08 define each category's taxable amount as
     * that category's line nets minus the document allowances *attributed to
     * that category* — attribution being BT-95, the tax category on the
     * allowance element itself.
     *
     * A single allowance carrying one category therefore cannot express a
     * discount spread across several. This emitted exactly that: one element
     * tagged 'S', while the subtotals below reduced every category in
     * proportion. The two descriptions of the same discount disagreed, and an
     * invoice mixing standard and zero-rated lines under a discount failed
     * both rules — each category's declared base matched neither what the
     * allowance said nor what the lines said.
     *
     * Splitting the element the same way the bases are split makes the
     * document say one thing. Both now read from breakdown().
     */
    private function addAllowanceCharge(DOMDocument $dom, InvoiceXmlData $data): void
    {
        if ($data->discount <= 0) {
            return;
        }

        foreach ($this->breakdown($data->lines, (float) $data->discount, $this->fixedTax($data)) as $part) {
            if ($part['share'] <= 0.0) {
                continue;
            }

            $dom->documentElement->appendChild($this->buildAllowance($dom, $data, $part));
        }
    }

    /**
     * @param  array{category: string, rate: float, share: float}  $part
     */
    private function buildAllowance(DOMDocument $dom, InvoiceXmlData $data, array $part): DOMElement
    {
        $allowanceCharge = $dom->createElementNS(Ubl::CAC_NS, 'cac:AllowanceCharge');

        // ChargeIndicator: false = allowance (discount), true = charge
        $allowanceCharge->appendChild(
            $dom->createElementNS(Ubl::CBC_NS, 'cbc:ChargeIndicator', 'false')
        );

        // AllowanceChargeReasonCode (ZATCA recommendation: 95 = discount)
        $allowanceCharge->appendChild(
            $dom->createElementNS(Ubl::CBC_NS, 'cbc:AllowanceChargeReasonCode', '95')
        );

        // AllowanceChargeReason (required for ZATCA)
        $reason = $data->discountReason ?? 'Discount';
        $allowanceCharge->appendChild(
            $dom->createElementNS(Ubl::CBC_NS, 'cbc:AllowanceChargeReason', $reason)
        );

        // Amount — this category's share of the discount, not the whole of it.
        $amount = $dom->createElementNS(
            Ubl::CBC_NS,
            'cbc:Amount',
            Ubl::amount($part['share'])
        );
        $amount->setAttribute('currencyID', $data->currency);
        $allowanceCharge->appendChild($amount);

        // No BaseAmount, and no MultiplierFactorNumeric either.
        //
        // BT-93 and BT-94 travel together: BR-KSA-EN16931-05 requires the
        // percentage whenever the base amount is given, and
        // BR-KSA-EN16931-03 then checks that amount = base × percentage ÷ 100.
        // This emitted the base and, in the line above, deliberately skipped
        // the percentage as "optional" — which is true of the pair and not of
        // one without the other, so every discounted invoice drew both rules.
        //
        // ZATCA's own document-level charge sample carries neither: an Amount
        // and a TaxCategory is the whole element. A flat discount has no
        // percentage to state, so stating a base it cannot be checked against
        // adds nothing.

        // BT-95: which category this share belongs to. It is what makes the
        // allowance attributable, and therefore what BR-S-08 and BR-Z-08 read.
        $taxCategory = $dom->createElementNS(Ubl::CAC_NS, 'cac:TaxCategory');
        $taxCategory->appendChild($dom->createElementNS(Ubl::CBC_NS, 'cbc:ID', $part['category']));
        $taxCategory->appendChild(
            $dom->createElementNS(Ubl::CBC_NS, 'cbc:Percent', Ubl::amount($part['rate']))
        );
        $taxScheme = $dom->createElementNS(Ubl::CAC_NS, 'cac:TaxScheme');
        $taxScheme->appendChild($dom->createElementNS(Ubl::CBC_NS, 'cbc:ID', 'VAT'));
        $taxCategory->appendChild($taxScheme);
        $allowanceCharge->appendChild($taxCategory);

        return $allowanceCharge;
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
    private function addTaxTotal(DOMDocument $dom, InvoiceXmlData $data): void
    {
        $root = $dom->documentElement;
        $isMultiCurrency = $data->isMultiCurrency();

        // Calculate SAR tax amount for multi-currency invoices
        $taxAmountSar = $isMultiCurrency
            ? $data->convertToSar($data->taxAmount)
            : $data->taxAmount;

        // First TaxTotal - document currency with subtotals (BT-110)
        $taxTotal = $dom->createElementNS(Ubl::CAC_NS, 'cac:TaxTotal');

        // Total tax amount in document currency
        $taxAmount = $dom->createElementNS(Ubl::CBC_NS, 'cbc:TaxAmount', Ubl::amount($data->taxAmount));
        $taxAmount->setAttribute('currencyID', $data->currency);
        $taxTotal->appendChild($taxAmount);

        // Tax subtotals by category (in document currency)
        if (! empty($data->taxSubtotals)) {
            foreach ($data->taxSubtotals as $subtotal) {
                $taxTotal->appendChild($this->buildTaxSubtotal($dom, $subtotal, $data->currency));
            }
        } else {
            // Aggregate tax subtotals from invoice lines by category and rate
            $aggregated = array_values($this->breakdown($data->lines, (float) $data->discount, $this->fixedTax($data)));
            foreach ($aggregated as $subtotal) {
                $taxTotal->appendChild($this->buildTaxSubtotal($dom, $subtotal, $data->currency));
            }
        }

        $root->appendChild($taxTotal);

        // Second TaxTotal - SAR amount only (BT-111, required for multi-currency)
        // Per ZATCA: "If the VAT accounting currency code (BT-6) is present,
        // then the Invoice total VAT amount in accounting currency (BT-111) shall be provided"
        $taxTotal2 = $dom->createElementNS(Ubl::CAC_NS, 'cac:TaxTotal');
        $taxAmount2 = $dom->createElementNS(
            Ubl::CBC_NS,
            'cbc:TaxAmount',
            Ubl::amount($isMultiCurrency ? $taxAmountSar : $data->taxAmount)
        );
        // For multi-currency: SAR; for SAR invoices: same currency
        $taxAmount2->setAttribute('currencyID', $isMultiCurrency ? 'SAR' : $data->currency);
        $taxTotal2->appendChild($taxAmount2);
        $root->appendChild($taxTotal2);
    }

    /**
     * The invoice's tax breakdown: one entry per category and rate, carrying
     * that group's net, its share of any document discount, and the tax due on
     * what is left.
     *
     * The single source for both the allowance elements and the tax subtotals.
     * They describe the same discount from two directions and ZATCA checks
     * that they agree, so they are computed once.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<string, array{category: string, rate: float, net: float, share: float, taxableAmount: float, taxAmount: float, taxExemptionReason: ?string, taxExemptionReasonCode: ?string}>
     */
    private function breakdown(array $lines, float $allowance, ?float $tax = null): array
    {
        $groups = [];

        foreach ($lines as $line) {
            $category = $line['taxCategory'] ?? 'S';
            $rate = (float) ($line['taxRate'] ?? 15.0);
            $key = $category.'_'.$rate;

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    // 'category'/'rate' name the group; 'taxCategory'/
                    // 'taxPercent' are the same two values under the names
                    // buildTaxSubtotal() reads. One array serves both readers.
                    'category' => $category,
                    'rate' => $rate,
                    'taxCategory' => $category,
                    'taxPercent' => $rate,
                    'net' => 0.0,
                    'share' => 0.0,
                    'taxableAmount' => 0.0,
                    'taxAmount' => 0.0,
                    'taxExemptionReason' => $line['taxExemptionReason'] ?? null,
                    'taxExemptionReasonCode' => $line['taxExemptionReasonCode'] ?? null,
                ];
            }

            // The line net, not lineTotal: that already includes the tax this
            // group exists to explain. The stored net when there is one - a
            // price backed out of a VAT-inclusive one is rounded, so quantity
            // times it is not always the net.
            $groups[$key]['net'] += Ubl::lineNet($line);
        }

        // A discount on the whole invoice reaches every category in proportion
        // to what that category contributed. Tax is then recomputed on the
        // reduced base rather than carried over from the lines, which were
        // priced before the discount existed.
        $categories = InvoiceTotals::categories(
            array_map(static fn (array $g): float => $g['net'], $groups),
            array_map(static fn (array $g): float => $g['rate'], $groups),
            $allowance,
            $tax
        );

        foreach ($categories as $key => $category) {
            $groups[$key]['share'] = $category['share'];
            $groups[$key]['taxableAmount'] = $category['base'];
            $groups[$key]['taxAmount'] = $category['tax'];
        }

        return $groups;
    }

    /**
     * The document's VAT when it is fixed rather than derived.
     *
     * Prices entered with VAT in them keep the customer's total exact, so the
     * VAT is what that total leaves over the net, and the categories are made
     * to add up to it (InvoiceTotals::categories()). Otherwise null: the
     * categories decide it.
     */
    private function fixedTax(InvoiceXmlData $data): ?float
    {
        return $data->isTaxInclusive ? (float) $data->taxAmount : null;
    }

    /**
     * Build tax subtotal element.
     */
    private function buildTaxSubtotal(DOMDocument $dom, array $subtotal, string $currency): DOMElement
    {
        $taxSubtotal = $dom->createElementNS(Ubl::CAC_NS, 'cac:TaxSubtotal');

        // Taxable amount
        $taxableAmount = $dom->createElementNS(
            Ubl::CBC_NS,
            'cbc:TaxableAmount',
            Ubl::amount($subtotal['taxableAmount'])
        );
        $taxableAmount->setAttribute('currencyID', $currency);
        $taxSubtotal->appendChild($taxableAmount);

        // Tax amount
        $taxAmount = $dom->createElementNS(
            Ubl::CBC_NS,
            'cbc:TaxAmount',
            Ubl::amount($subtotal['taxAmount'])
        );
        $taxAmount->setAttribute('currencyID', $currency);
        $taxSubtotal->appendChild($taxAmount);

        // Tax category
        $taxCategory = $dom->createElementNS(Ubl::CAC_NS, 'cac:TaxCategory');
        $taxCategory->appendChild($dom->createElementNS(Ubl::CBC_NS, 'cbc:ID', $subtotal['taxCategory']));
        $taxCategory->appendChild(
            $dom->createElementNS(Ubl::CBC_NS, 'cbc:Percent', Ubl::amount($subtotal['taxPercent']))
        );

        // Tax exemption reason (for zero-rated or exempt)
        if (! empty($subtotal['taxExemptionReason'])) {
            $taxCategory->appendChild(
                $dom->createElementNS(Ubl::CBC_NS, 'cbc:TaxExemptionReasonCode', $subtotal['taxExemptionReasonCode'] ?? '')
            );
            $taxCategory->appendChild(
                $dom->createElementNS(Ubl::CBC_NS, 'cbc:TaxExemptionReason', $subtotal['taxExemptionReason'])
            );
        }

        $taxScheme = $dom->createElementNS(Ubl::CAC_NS, 'cac:TaxScheme');
        $taxScheme->appendChild($dom->createElementNS(Ubl::CBC_NS, 'cbc:ID', 'VAT'));
        $taxCategory->appendChild($taxScheme);

        $taxSubtotal->appendChild($taxCategory);

        return $taxSubtotal;
    }

    /**
     * Add legal monetary total.
     */
    private function addLegalMonetaryTotal(DOMDocument $dom, InvoiceXmlData $data): void
    {
        $total = $dom->createElementNS(Ubl::CAC_NS, 'cac:LegalMonetaryTotal');

        // Line extension amount (sum of line totals before tax)
        $lineExt = $dom->createElementNS(Ubl::CBC_NS, 'cbc:LineExtensionAmount', Ubl::amount($data->subtotal));
        $lineExt->setAttribute('currencyID', $data->currency);
        $total->appendChild($lineExt);

        // Tax exclusive amount
        // BR-CO-13: the taxable total is the line extension less any
        // allowance, not the line extension itself.
        $taxExcl = $dom->createElementNS(
            Ubl::CBC_NS,
            'cbc:TaxExclusiveAmount',
            Ubl::amount($data->subtotal - $data->discount)
        );
        $taxExcl->setAttribute('currencyID', $data->currency);
        $total->appendChild($taxExcl);

        // Tax inclusive amount
        $taxIncl = $dom->createElementNS(Ubl::CBC_NS, 'cbc:TaxInclusiveAmount', Ubl::amount($data->total));
        $taxIncl->setAttribute('currencyID', $data->currency);
        $total->appendChild($taxIncl);

        // Allowance total (discount)
        if ($data->discount > 0) {
            $allowance = $dom->createElementNS(Ubl::CBC_NS, 'cbc:AllowanceTotalAmount', Ubl::amount($data->discount));
            $allowance->setAttribute('currencyID', $data->currency);
            $total->appendChild($allowance);
        }

        // Prepaid amount
        if ($data->prepaidAmount > 0) {
            $prepaid = $dom->createElementNS(Ubl::CBC_NS, 'cbc:PrepaidAmount', Ubl::amount($data->prepaidAmount));
            $prepaid->setAttribute('currencyID', $data->currency);
            $total->appendChild($prepaid);
        }

        // Payable amount
        $payable = $dom->createElementNS(
            Ubl::CBC_NS,
            'cbc:PayableAmount',
            Ubl::amount($data->total - $data->prepaidAmount)
        );
        $payable->setAttribute('currencyID', $data->currency);
        $total->appendChild($payable);

        $dom->documentElement->appendChild($total);
    }
}
