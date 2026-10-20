<?php

declare(strict_types=1);

namespace App\Domains\Billing\DTOs;

use App\Domains\Billing\Enums\VatTreatment;
use Carbon\CarbonImmutable;

/**
 * An expense as it is entered: whose bill, of what day, its number on the
 * supplier's side, what it was for, its amounts in the currency it was
 * billed in, how its VAT is treated, and - for a currency other than the
 * tax currency - the rate that day when it is typed rather than taken from
 * the rate table. No due date: the supplier's terms from the bill's date.
 */
final readonly class ExpenseEntry
{
    public function __construct(
        public string $supplierId,
        public CarbonImmutable $expenseDate,
        public string $description,
        public string $category,
        public string $currency,
        public string $net,
        public string $vat,
        public VatTreatment $vatTreatment,
        public ?string $supplierInvoiceNumber = null,
        public ?string $costCentre = null,
        public ?string $exchangeRate = null,
        public ?string $notes = null,
        public ?CarbonImmutable $dueDate = null,
    ) {}
}
