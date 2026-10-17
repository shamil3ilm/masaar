<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Document;

use App\Domains\Compliance\Countries\SaudiArabia\DTOs\InvoiceXmlData;
use App\Domains\Invoice\Support\UnitCode;
use DOMDocument;
use DOMElement;

/**
 * An invoice's lines, each with its own tax total, item and price.
 */
class UblLineBuilder
{
    /**
     * Add invoice lines.
     *
     * Handles:
     * - Standard priced items
     * - Tax-inclusive pricing (converted to net)
     * - Free goods/samples with market value for VAT (deemed supply)
     * - Line-level discounts
     */
    public function addLines(DOMDocument $dom, InvoiceXmlData $data): void
    {
        foreach ($data->lines as $index => $line) {
            $dom->documentElement->appendChild($this->buildLine($dom, $data, $index, $line));
        }
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function buildLine(DOMDocument $dom, InvoiceXmlData $data, int $index, array $line): DOMElement
    {
        $invoiceLine = $dom->createElementNS(Ubl::CAC_NS, 'cac:InvoiceLine');

        // Line ID
        $invoiceLine->appendChild(Ubl::element($dom, Ubl::CBC_NS, 'cbc:ID', (string) ($index + 1)));

        // Invoiced quantity
        $quantity = Ubl::element($dom, Ubl::CBC_NS, 'cbc:InvoicedQuantity', $this->formatQuantity($line['quantity']));
        $quantity->setAttribute('unitCode', UnitCode::normalise($line['unitCode'] ?? null));
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

        // Prices are stored net whichever way they were entered; a price
        // that had VAT in it was converted when the invoice was drafted.
        // This converted again from the unit price, which by then was net.
        $netUnitPrice = $this->formatPrice($unitPrice);
        // Quantity times price less the line's discount, not lineTotal: that
        // carries the tax. The stored net when there is one, which is
        // already less the discount.
        $lineNet = $isFreeItem ? round($unitPrice * (float) $line['quantity'], 2) - $lineDiscount : Ubl::lineNet($line);
        $lineTaxAmount = (float) ($line['taxAmount'] ?? InvoiceXmlData::calculateTaxFromNet($lineNet, $taxRate));

        // A net backed out of a gross price is rounded to the halalah, so
        // the price that reproduces the line net (BR-KSA-EN16931-11:
        // quantity times price, less the line's allowance) may need more
        // places. ZATCA sets no limit on the price's decimals.
        $beforeAdjustments = round($lineNet + $lineDiscount - Ubl::lineCharges($line), 2);

        if (! $isFreeItem && (float) $line['quantity'] > 0
            && round($unitPrice * (float) $line['quantity'], 2) !== $beforeAdjustments) {
            $netUnitPrice = $this->priceFor($beforeAdjustments, (float) $line['quantity']);
        }

        // For free items: lineNet = 0 but VAT still calculated on market value
        // The invoice XML shows 0 amount but includes VAT on deemed supply
        $displayLineNet = $isFreeItem ? 0.0 : $lineNet;

        // For free items: Show 0 as line amount but include VAT note
        $lineAmount = Ubl::element($dom, Ubl::CBC_NS, 'cbc:LineExtensionAmount', Ubl::amount($displayLineNet));
        $lineAmount->setAttribute('currencyID', $data->currency);
        $invoiceLine->appendChild($lineAmount);

        // Add note for free items indicating deemed supply
        if ($isFreeItem && $unitPrice > 0) {
            $freeNote = Ubl::element($dom, Ubl::CBC_NS, 'cbc:Note', 'Free item - VAT on deemed supply (market value: '.Ubl::amount($unitPrice).' '.$data->currency.')');
            $invoiceLine->appendChild($freeNote);
        }

        // Line-level AllowanceCharge (discount) if present
        if ($lineDiscount > 0) {
            $invoiceLine->appendChild($this->buildLineAllowanceCharge(
                $dom,
                $lineDiscount,
                $line['discountReason'] ?? 'Line discount',
                $data->currency
            ));
        }

        // The line's own charges (BG-28): each with its UNCL 7161 code and
        // its reason (BR-KSA-20, BR-KSA-22, BR-KSA-CL-06), after the
        // allowance and before the line's tax total, as UBL orders them.
        foreach ((array) ($line['charges'] ?? []) as $charge) {
            $invoiceLine->appendChild($this->buildLineCharge($dom, $charge, $data->currency));
        }

        $invoiceLine->appendChild($this->buildLineTaxTotal($dom, $data->currency, $displayLineNet, $lineTaxAmount));
        $invoiceLine->appendChild($this->buildItem($dom, $line));
        $invoiceLine->appendChild($this->buildPrice($dom, $line, $netUnitPrice, $data->currency));

        return $invoiceLine;
    }

    /**
     * Tax total for this line (use calculated tax amount for tax-inclusive support).
     */
    private function buildLineTaxTotal(DOMDocument $dom, string $currency, float $displayLineNet, float $lineTaxAmount): DOMElement
    {
        $lineTax = $dom->createElementNS(Ubl::CAC_NS, 'cac:TaxTotal');
        $lineTaxAmountEl = Ubl::element($dom, Ubl::CBC_NS, 'cbc:TaxAmount', Ubl::amount($lineTaxAmount));
        $lineTaxAmountEl->setAttribute('currencyID', $currency);
        $lineTax->appendChild($lineTaxAmountEl);

        // KSA-12, the line amount with VAT.
        //
        // Inside a line's TaxTotal, cbc:RoundingAmount is not a rounding
        // correction — ZATCA reads it as the line total including tax, and
        // BR-KSA-51 requires it to equal the line net (BT-131) plus the
        // line VAT (KSA-11). Zero here declares the line's gross value as
        // nothing, and ZATCA reports that as an advisory rather than an
        // error — the document clears with a stated total of zero, so
        // nothing downstream objects.
        $rounding = Ubl::element($dom, Ubl::CBC_NS, 'cbc:RoundingAmount', Ubl::amount($displayLineNet + $lineTaxAmount));
        $rounding->setAttribute('currencyID', $currency);
        $lineTax->appendChild($rounding);

        return $lineTax;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function buildItem(DOMDocument $dom, array $line): DOMElement
    {
        $item = $dom->createElementNS(Ubl::CAC_NS, 'cac:Item');
        $item->appendChild(Ubl::element($dom, Ubl::CBC_NS, 'cbc:Name', $line['description']));

        // Item classification code (UNSPSC or GS1) if provided
        if (! empty($line['itemClassificationCode'])) {
            $commodityClass = $dom->createElementNS(Ubl::CAC_NS, 'cac:CommodityClassification');
            $classCode = Ubl::element($dom, Ubl::CBC_NS, 'cbc:ItemClassificationCode', $line['itemClassificationCode']);
            $classCode->setAttribute('listID', 'UNSPSC');
            $commodityClass->appendChild($classCode);
            $item->appendChild($commodityClass);
        }

        // Item tax category
        $itemTaxCat = $dom->createElementNS(Ubl::CAC_NS, 'cac:ClassifiedTaxCategory');
        $itemTaxCat->appendChild(Ubl::element($dom, Ubl::CBC_NS, 'cbc:ID', $line['taxCategory'] ?? 'S'));
        $itemTaxCat->appendChild(Ubl::element($dom, Ubl::CBC_NS, 'cbc:Percent', Ubl::amount($line['taxRate'] ?? 15)));
        $itemTaxScheme = $dom->createElementNS(Ubl::CAC_NS, 'cac:TaxScheme');
        $itemTaxScheme->appendChild(Ubl::element($dom, Ubl::CBC_NS, 'cbc:ID', 'VAT'));
        $itemTaxCat->appendChild($itemTaxScheme);
        $item->appendChild($itemTaxCat);

        return $item;
    }

    /**
     * Price (net unit price - ZATCA requires tax-exclusive price in XML).
     *
     * @param  array<string, mixed>  $line
     */
    private function buildPrice(DOMDocument $dom, array $line, string $netUnitPrice, string $currency): DOMElement
    {
        $price = $dom->createElementNS(Ubl::CAC_NS, 'cac:Price');
        $priceAmount = Ubl::element($dom, Ubl::CBC_NS, 'cbc:PriceAmount', $netUnitPrice);
        $priceAmount->setAttribute('currencyID', $currency);
        $price->appendChild($priceAmount);

        // BaseQuantity (optional but good for clarity)
        $baseQty = Ubl::element($dom, Ubl::CBC_NS, 'cbc:BaseQuantity', '1');
        $baseQty->setAttribute('unitCode', UnitCode::normalise($line['unitCode'] ?? null));
        $price->appendChild($baseQty);

        return $price;
    }

    /**
     * Build line-level AllowanceCharge element.
     */
    private function buildLineAllowanceCharge(
        DOMDocument $dom,
        float $amount,
        string $reason,
        string $currency
    ): DOMElement {
        $allowanceCharge = $dom->createElementNS(Ubl::CAC_NS, 'cac:AllowanceCharge');

        // ChargeIndicator: false = allowance (discount)
        $allowanceCharge->appendChild(
            Ubl::element($dom, Ubl::CBC_NS, 'cbc:ChargeIndicator', 'false')
        );

        // AllowanceChargeReasonCode (95 = discount)
        $allowanceCharge->appendChild(
            Ubl::element($dom, Ubl::CBC_NS, 'cbc:AllowanceChargeReasonCode', '95')
        );

        // AllowanceChargeReason
        $allowanceCharge->appendChild(
            Ubl::element($dom, Ubl::CBC_NS, 'cbc:AllowanceChargeReason', $reason)
        );

        // Amount
        $amountEl = Ubl::element($dom, Ubl::CBC_NS, 'cbc:Amount', Ubl::amount($amount));
        $amountEl->setAttribute('currencyID', $currency);
        $allowanceCharge->appendChild($amountEl);

        return $allowanceCharge;
    }

    /**
     * A line charge: the indicator, its code and reason, then its
     * percentage, amount and base - the percentage and base only together
     * (BR-KSA-EN16931-04, -05), and the amount their product
     * (BR-KSA-EN16931-03).
     *
     * @param  array<string, mixed>  $charge
     */
    private function buildLineCharge(DOMDocument $dom, array $charge, string $currency): DOMElement
    {
        $element = $dom->createElementNS(Ubl::CAC_NS, 'cac:AllowanceCharge');
        $element->appendChild(Ubl::element($dom, Ubl::CBC_NS, 'cbc:ChargeIndicator', 'true'));
        $element->appendChild(Ubl::element($dom, Ubl::CBC_NS, 'cbc:AllowanceChargeReasonCode', (string) $charge['reasonCode']));
        $element->appendChild(Ubl::element($dom, Ubl::CBC_NS, 'cbc:AllowanceChargeReason', (string) $charge['reason']));

        Ubl::chargeAmounts($dom, $element, $charge, $currency);

        return $element;
    }

    /**
     * Format a unit price to two places, as every price entered is.
     */
    private function formatPrice(float $price): string
    {
        return number_format($price, 2, '.', '');
    }

    /**
     * The fewest places of net price at which quantity times price comes back
     * to the line net, as ZATCA checks it (BR-KSA-EN16931-11, rounded to the
     * halalah).
     *
     * A net backed out of a VAT-inclusive price is decided per line, so the
     * unit price is the derived figure. Six places served until about a
     * million units, where they no longer reached the halalah; ZATCA sets no
     * limit on a price's decimals, so this takes what the quantity needs.
     */
    private function priceFor(float $lineNet, float $quantity): string
    {
        $net = number_format($lineNet, 2, '.', '');
        $qty = number_format($quantity, 3, '.', '');

        for ($places = 2; $places <= 12; $places++) {
            $price = $this->halfUp(bcdiv($net, $qty, $places + 1), $places);

            if ($this->halfUp(bcmul($qty, $price, $places + 3), 2) === $net) {
                return $price;
            }
        }

        return $this->halfUp(bcdiv($net, $qty, 13), 12);
    }

    /**
     * Round a non-negative decimal string half up to the given places.
     */
    private function halfUp(string $value, int $places): string
    {
        return bcadd($value, '0.'.str_repeat('0', $places).'5', $places);
    }

    /**
     * Format quantity (3 decimal places).
     */
    private function formatQuantity(float $quantity): string
    {
        return number_format($quantity, 3, '.', '');
    }
}
