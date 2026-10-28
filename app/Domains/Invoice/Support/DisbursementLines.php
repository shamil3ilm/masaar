<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Support;

use App\Domains\Compliance\Engine\DTOs\DisbursementTreatment;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A disbursement line: a cost paid in the customer's name and for its
 * account, passed on at cost, outside the value of the supply. Never
 * charged VAT - its category and reason are the country's
 * (SpecialSupplies::disbursement) whatever the line said - and only once
 * the seller confirms the conditions for it (disbursements_confirmed).
 * Where the country's texts do not say how one is treated, no line may be
 * one.
 *
 * Each country's InvoiceRules applies it, as ChargeRules is.
 */
final class DisbursementLines
{
    public const KIND = 'disbursement';

    /**
     * A line marked as a disbursement, with the country's treatment in
     * place of its own.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    public static function treated(array $line, ?DisbursementTreatment $treatment): array
    {
        if (($line['kind'] ?? null) !== self::KIND || $treatment === null) {
            return $line;
        }

        return [
            ...$line,
            'tax_category' => $treatment->category,
            'tax_rate' => 0,
            'exempt_code' => $treatment->exemptCode,
            'exempt_reason' => filled($line['exempt_reason'] ?? null) ? $line['exempt_reason'] : $treatment->reason,
        ];
    }

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'lines.*.kind' => ['nullable', 'string', Rule::in([self::KIND])],
            'disbursements_confirmed' => ['nullable', 'boolean'],
        ];
    }

    public static function after(Validator $validator, Request $request, ?DisbursementTreatment $treatment): void
    {
        $disbursements = array_keys(array_filter((array) $request->input('lines', []), fn (mixed $line): bool => is_array($line) && ($line['kind'] ?? null) === self::KIND));

        if ($disbursements === []) {
            return;
        }

        if ($treatment === null) {
            foreach ($disbursements as $index) {
                $validator->errors()->add("lines.{$index}.kind", __('Line :line: disbursement lines are not offered for your country yet.', ['line' => (int) $index + 1]));
            }

            return;
        }

        if (! $request->boolean('disbursements_confirmed')) {
            $validator->errors()->add('disbursements_confirmed', __('Confirm that each disbursement meets the conditions (:source): :conditions', [
                'source' => $treatment->source,
                'conditions' => implode(' ', array_map(fn (string $condition): string => __($condition), $treatment->conditions)),
            ]));
        }
    }

    /**
     * What the invoice's disbursement lines come to, in its currency: shown
     * apart from the supply on the printed invoice and in the reports.
     *
     * @param  iterable<object>  $lines  invoice lines
     */
    public static function total(iterable $lines): string
    {
        $total = '0.00';

        foreach ($lines as $line) {
            if (($line->kind ?? null) === self::KIND) {
                $total = bcadd($total, (string) $line->line_total, 2);
            }
        }

        return $total;
    }
}
