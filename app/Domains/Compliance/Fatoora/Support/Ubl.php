<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Support;

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
