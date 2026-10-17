<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The shape of an invoice's line discounts and charges, which every
 * country's rules take the same way: a line's discount_amount and charges,
 * and the document's charges.
 *
 * A charge is an amount or a percentage of a base - one or the other - with
 * its reason in words and, from the country's list, as a code. What a
 * country decides is which codes, tax categories and exemptions it takes,
 * and what its categories' rates must be: those are its own InvoiceRules'.
 */
final class ChargeRules
{
    /** More charges than these on one line, or one invoice, is a mistake. */
    private const LINE_CHARGES = 5;

    private const DOCUMENT_CHARGES = 20;

    /**
     * @param  list<string>  $reasonCodes  The UNCL 7161 codes the country takes
     * @param  list<string>  $categories  The tax categories a document charge may be at
     * @param  list<string>  $exemptionCodes
     * @return array<string, mixed>
     */
    public static function rules(array $reasonCodes, array $categories, array $exemptionCodes): array
    {
        return [
            'lines.*.discount_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'lines.*.charges' => ['nullable', 'array', 'max:'.self::LINE_CHARGES],
            ...self::charge('lines.*.charges.*', $reasonCodes),

            'charges' => ['nullable', 'array', 'max:'.self::DOCUMENT_CHARGES],
            ...self::charge('charges.*', $reasonCodes),
            'charges.*.tax_category' => ['required', Rule::in($categories)],
            'charges.*.tax_rate' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'charges.*.exempt_code' => ['nullable', 'string', Rule::in($exemptionCodes)],
            'charges.*.exempt_reason' => ['nullable', 'string', 'max:255', 'required_with:charges.*.exempt_code'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'lines.*.charges.*.amount.required_without' => __('A charge is an amount or a percentage.'),
            'charges.*.amount.required_without' => __('A charge is an amount or a percentage.'),
            'lines.*.charges.*.amount.prohibits' => __('A charge is an amount or a percentage, not both.'),
            'charges.*.amount.prohibits' => __('A charge is an amount or a percentage, not both.'),
        ];
    }

    /**
     * A base names what a percentage is taken of, so it comes with one; and
     * a line's net cannot go below nothing (BR-KSA-F-04, and PINT AE's
     * amounts are not negative either).
     */
    public static function after(Validator $validator, Request $request): void
    {
        $errors = $validator->errors();

        foreach ((array) $request->input('charges', []) as $index => $charge) {
            self::baseWithPercent($validator, (array) $charge, "charges.{$index}");
        }

        foreach ((array) $request->input('lines', []) as $index => $line) {
            if (! is_array($line) || $errors->has("lines.{$index}.*") || $errors->has("lines.{$index}.quantity") || $errors->has("lines.{$index}.unit_price")) {
                continue;
            }

            foreach ((array) ($line['charges'] ?? []) as $position => $charge) {
                self::baseWithPercent($validator, (array) $charge, "lines.{$index}.charges.{$position}");
            }

            if (! is_numeric($line['quantity'] ?? null) || ! is_numeric($line['unit_price'] ?? null)) {
                continue;
            }

            $gross = bcmul((string) $line['quantity'], (string) $line['unit_price'], 6);

            if (bccomp((string) ($line['discount_amount'] ?? '0'), bcadd($gross, self::lineCharges($line, $gross), 6), 6) > 0) {
                $errors->add("lines.{$index}.discount_amount", __('Line :line: the discount cannot be more than the line.', ['line' => $index + 1]));
            }
        }
    }

    /**
     * The charge rows a form sent that were used: one with a reason, an
     * amount or a percentage. A form posts every row it holds, the empty
     * one waiting to be filled in among them, and a picker or a default
     * rate alone is not a charge. Null when none was.
     *
     * @return list<array<string, mixed>>|null
     */
    public static function used(mixed $rows): ?array
    {
        if (! is_array($rows)) {
            return null;
        }

        $used = array_values(array_filter($rows, fn (mixed $row): bool => is_array($row)
            && array_filter(array_intersect_key($row, array_flip(['reason', 'amount', 'percent'])), fn (mixed $v): bool => trim((string) $v) !== '') !== []));

        return $used === [] ? null : $used;
    }

    /**
     * What a line comes to before the document's own discount and charges:
     * quantity times price, less its discount, plus its charges.
     *
     * @param  array<string, mixed>  $line
     */
    public static function lineTotal(array $line): string
    {
        $gross = bcmul((string) $line['quantity'], (string) $line['unit_price'], 6);

        return bcadd(bcsub($gross, (string) ($line['discount_amount'] ?? '0'), 6), self::lineCharges($line, $gross), 6);
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private static function lineCharges(array $line, string $gross): string
    {
        $sum = '0';

        foreach ((array) ($line['charges'] ?? []) as $charge) {
            if (! is_array($charge)) {
                continue;
            }

            $percent = $charge['percent'] ?? null;
            $sum = bcadd($sum, is_numeric($percent)
                ? bcdiv(bcmul(is_numeric($charge['base_amount'] ?? null) ? (string) $charge['base_amount'] : $gross, (string) $percent, 6), '100', 6)
                : (is_numeric($charge['amount'] ?? null) ? (string) $charge['amount'] : '0'), 6);
        }

        return $sum;
    }

    /**
     * @param  array<string, mixed>  $charge
     */
    private static function baseWithPercent(Validator $validator, array $charge, string $field): void
    {
        if (filled($charge['base_amount'] ?? null) && blank($charge['percent'] ?? null)) {
            $validator->errors()->add("{$field}.base_amount", __('A base is what a percentage is taken of: give the percentage too.'));
        }
    }

    /**
     * One charge's fields, at a path.
     *
     * @param  list<string>  $reasonCodes
     * @return array<string, mixed>
     */
    private static function charge(string $path, array $reasonCodes): array
    {
        return [
            "{$path}.reason" => ['required', 'string', 'max:255'],
            "{$path}.reason_code" => ['nullable', 'string', Rule::in($reasonCodes)],
            "{$path}.amount" => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2', "required_without:{$path}.percent", "prohibits:{$path}.percent"],
            // Two places at most (BR-KSA-DEC-01).
            "{$path}.percent" => ['nullable', 'numeric', 'gt:0', 'max:100', 'decimal:0,2'],
            "{$path}.base_amount" => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
        ];
    }
}
