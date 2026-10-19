<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\DTOs;

/**
 * A field a country requires on the printed copy of an invoice, and the rule
 * that requires it.
 *
 * An invoice template restyles and moves these but never hides them: the
 * country's page and receipt print each one, and the template screens show
 * them as locked, with $source as the reason.
 *
 * Required on a document type ('standard', 'simplified' - the invoice's
 * type) always, or only when the invoice has a value for it: a supply date,
 * a discount, the reason a note was issued.
 */
final readonly class PrintedField
{
    public const STANDARD = 'standard';

    public const SIMPLIFIED = 'simplified';

    /** A line's discount: a template column that can be locked on. */
    public const LINE_DISCOUNT = 'line_discount';

    /** A line's VAT rate: a template column that can be locked on. */
    public const LINE_VAT_RATE = 'line_vat_rate';

    /**
     * @param  string  $key  what is printed, in the shared vocabulary: invoice_number, seller_vat_number, line_vat_rate ...
     * @param  string  $source  the rule, cited: the law's article and the authority's own field id
     * @param  list<string>  $always  the document types it is printed on whatever the invoice holds
     * @param  list<string>  $whenGiven  the document types it is printed on when the invoice has it
     */
    public function __construct(
        public string $key,
        public PrintLabel $label,
        public string $source,
        public array $always = [self::STANDARD, self::SIMPLIFIED],
        public array $whenGiven = [],
    ) {}

    /** Whether a document of the type must print it, given whether the invoice has it. */
    public function requiredOn(string $type, bool $given = true): bool
    {
        return in_array($type, $this->always, true) || ($given && in_array($type, $this->whenGiven, true));
    }
}
