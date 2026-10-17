<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Document;

use DOMDocument;
use DOMElement;

/**
 * The namespaces and number formats every part of a UBL invoice shares.
 *
 * XmlBuilder writes a document in parts, each its own builder, and the parts
 * have to agree on these to the byte: an amount formatted one way in a total
 * and another in a subtotal is two different numbers to ZATCA.
 */
final class Ubl
{
    public const INVOICE_NS = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';

    public const CAC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    public const CBC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    public const EXT_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2';

    /**
     * An element with its text, written as a text node.
     *
     * Never as createElementNS()'s third argument: DOM reads that as markup,
     * so "Al Noor Trading & Contracting" was an unterminated entity and the
     * element came out empty - a buyer, a seller or a line with no name, in
     * a signed tax invoice. A text node is escaped when it is written, and
     * text without &, < or > is written exactly as before.
     */
    public static function element(DOMDocument $dom, string $namespace, string $qualifiedName, string|int|float|null $text = null): DOMElement
    {
        $element = $dom->createElementNS($namespace, $qualifiedName);

        if ($text !== null) {
            $element->appendChild($dom->createTextNode((string) $text));
        }

        return $element;
    }

    /**
     * Format monetary amount (2 decimal places).
     */
    public static function amount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * A line's net (BT-131): the stored figure when there is one, otherwise
     * quantity times the unit price less the line's own discount plus its
     * own charges.
     *
     * @param  array<string, mixed>  $line
     */
    public static function lineNet(array $line): float
    {
        return isset($line['lineNet'])
            ? (float) $line['lineNet']
            : round(round((float) $line['quantity'] * (float) $line['unitPrice'], 2) - (float) ($line['discount'] ?? 0) + self::lineCharges($line), 2);
    }

    /**
     * A charge's figures, in UBL's order: the percentage (BT-101, BT-143)
     * when it is one, the amount, then the base (BT-100, BT-142) the
     * percentage was taken of. The percentage and the base travel together
     * (BR-KSA-EN16931-04, -05).
     *
     * @param  array<string, mixed>  $charge  {amount, percent?, base?}
     */
    public static function chargeAmounts(DOMDocument $dom, DOMElement $element, array $charge, string $currency): void
    {
        $percent = $charge['percent'] ?? null;
        $base = $charge['base'] ?? null;

        if ($percent !== null && $base !== null) {
            $element->appendChild(self::element($dom, self::CBC_NS, 'cbc:MultiplierFactorNumeric', self::amount((float) $percent)));
        }

        $amount = self::element($dom, self::CBC_NS, 'cbc:Amount', self::amount((float) $charge['amount']));
        $amount->setAttribute('currencyID', $currency);
        $element->appendChild($amount);

        if ($percent !== null && $base !== null) {
            $baseAmount = self::element($dom, self::CBC_NS, 'cbc:BaseAmount', self::amount((float) $base));
            $baseAmount->setAttribute('currencyID', $currency);
            $element->appendChild($baseAmount);
        }
    }

    /**
     * A line's own charges (BG-28), together.
     *
     * @param  array<string, mixed>  $line
     */
    public static function lineCharges(array $line): float
    {
        return round(array_sum(array_map(fn (array $charge): float => (float) $charge['amount'], (array) ($line['charges'] ?? []))), 2);
    }
}
