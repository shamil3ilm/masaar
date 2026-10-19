<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Templates;

use App\Domains\Compliance\Engine\DTOs\PrintedField;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Invoice\Support\PrintedAmounts;

/**
 * Which of a template's optional columns an invoice's lines print.
 *
 * The template asks for a column; the country can require one whatever the
 * template asks (Presentation::printedFields): the VAT rate on a tax
 * invoice, a line's discount whenever a line has one. A required column is
 * printed, and the template screens show it locked on, with the rule.
 */
final class PrintedColumns
{
    /** The columns a country can require, and the printed field each is. */
    private const FIELDS = [
        'discount' => PrintedField::LINE_DISCOUNT,
        'vat_rate' => PrintedField::LINE_VAT_RATE,
    ];

    /**
     * @param  list<PrintedField>  $fields  the country's
     * @return array<string, bool> by InvoiceTemplate::COLUMNS
     */
    public static function for(InvoiceTemplate $template, array $fields, Invoice $invoice): array
    {
        $type = self::type($invoice);
        $discounted = PrintedAmounts::anyLineDiscount($invoice->lines);
        $columns = $template->columns;

        foreach (self::FIELDS as $column => $key) {
            foreach ($fields as $field) {
                if ($field->key === $key && $field->requiredOn($type, $column === 'discount' ? $discounted : true)) {
                    $columns[$column] = true;
                }
            }
        }

        return $columns;
    }

    /**
     * The columns a country locks on, each with the fields that lock it: for
     * the template screens.
     *
     * @param  list<PrintedField>  $fields
     * @return array<string, PrintedField>
     */
    public static function locks(array $fields): array
    {
        $locks = [];

        foreach (self::FIELDS as $column => $key) {
            foreach ($fields as $field) {
                if ($field->key === $key) {
                    $locks[$column] = $field;
                }
            }
        }

        return $locks;
    }

    /**
     * Each line's item code, by line: the one it was issued with
     * (LineSnapshot), never the product as it reads today. Lines made from
     * no product have none.
     *
     * @return array<string, string>
     */
    public static function itemCodes(Invoice $invoice): array
    {
        $byLine = [];

        foreach ($invoice->lines as $line) {
            $code = trim((string) $line->item_code);

            if ($code !== '') {
                $byLine[(string) $line->id] = $code;
            }
        }

        return $byLine;
    }

    private static function type(Invoice $invoice): string
    {
        return $invoice->type instanceof \BackedEnum ? (string) $invoice->type->value : (string) $invoice->type;
    }
}
