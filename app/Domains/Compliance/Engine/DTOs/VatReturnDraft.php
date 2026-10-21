<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\DTOs;

/**
 * A country's VAT return drafted for a period: its sections of boxes, in
 * the order the form has them, the currency it is in, and what the reader
 * should know - documents left out, how a figure was reached.
 */
final readonly class VatReturnDraft
{
    /**
     * @param  array<string, list<VatReturnBox>>  $sections  heading => boxes
     * @param  list<string>  $notes  sentences, already translated
     * @param  list<string>  $problems  sentences, already translated
     */
    public function __construct(
        public string $title,
        public string $currency,
        public array $sections,
        public array $notes = [],
        public array $problems = [],
    ) {}

    /** One box by its key, wherever it sits. */
    public function box(string $key): ?VatReturnBox
    {
        foreach ($this->sections as $boxes) {
            foreach ($boxes as $box) {
                if ($box->key === $key) {
                    return $box;
                }
            }
        }

        return null;
    }
}
