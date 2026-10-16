<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Support;

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
     * A line's net: the stored figure when there is one, otherwise quantity
     * times the unit price.
     *
     * @param  array<string, mixed>  $line
     */
    public static function lineNet(array $line): float
    {
        return isset($line['lineNet'])
            ? (float) $line['lineNet']
            : round((float) $line['quantity'] * (float) $line['unitPrice'], 2);
    }
}
