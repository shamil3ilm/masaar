<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\DTOs;

/**
 * A country's VAT return drafted for a period: its sections of boxes, in
 * the order the form has them, the currency it is in, and what the reader
 * should know - documents left out, how a figure was reached.
 *
 * And, apart from the form, how its figures reconcile with another record -
 * where each sale stands with the tax authority. Shown and exported with
 * the form, never part of it: a filed period snapshots the sections only
 * (VatPeriods::figures), so an answer arriving later is not a change.
 */
final readonly class VatReturnDraft
{
    /**
     * @param  array<string, list<VatReturnBox>>  $sections  heading => boxes
     * @param  list<string>  $notes  sentences, already translated
     * @param  list<string>  $problems  sentences, already translated
     * @param  array<string, string>  $links  where to fix a problem: label, already translated => URL
     * @param  array<string, list<VatReturnBox>>  $reconciliation  heading => boxes, beside the form
     */
    public function __construct(
        public string $title,
        public string $currency,
        public array $sections,
        public array $notes = [],
        public array $problems = [],
        public array $links = [],
        public array $reconciliation = [],
    ) {}

    /** One box by its key, wherever it sits. */
    public function box(string $key): ?VatReturnBox
    {
        foreach ([...array_values($this->sections), ...array_values($this->reconciliation)] as $boxes) {
            foreach ($boxes as $box) {
                if ($box->key === $key) {
                    return $box;
                }
            }
        }

        return null;
    }
}
