<?php

declare(strict_types=1);

namespace App\Domains\Invoice\Support;

use App\Domains\Invoice\Enums\AdjustmentKind;

/**
 * What a return, an exchange, a discount or a correction on an issued
 * invoice will make, before anything is made (InvoiceAdjustments::plan):
 * shown to the person as a preview, then made exactly so.
 *
 * Each document is its role (credit, increase or invoice), its document
 * type, whether it is issued at once (a note, or the UAE's invoice for an
 * increase) or left a draft (a new sale), the draft as InvoiceDrafter takes
 * it with its country's columns, and what it adds up to.
 */
final readonly class AdjustmentPlan
{
    /**
     * @param  array<string, mixed>  $details  the lines and quantities, the discount, the corrected buyer, the person's note
     * @param  list<array{role: string, type: string, issue: bool, draft: array<string, mixed>, countryFields: array<string, mixed>, totals: array{subtotal: string, tax: string, total: string}, lines: list<array{description: string, quantity: string, unit_price: string, tax_rate: string}>}>  $documents
     */
    public function __construct(
        public AdjustmentKind $kind,
        public ?string $reason,
        public array $details,
        public array $documents,
    ) {}

    /**
     * As an API answer or a page shows it.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'kind' => $this->kind->value,
            'reason' => $this->reason,
            'details' => $this->details,
            'documents' => array_map(fn (array $document): array => [
                'role' => $document['role'],
                'document_type' => $document['type'],
                'issued_now' => $document['issue'],
                ...$document['totals'],
                'lines' => $document['lines'],
            ], $this->documents),
        ];
    }
}
