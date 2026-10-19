<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\DTOs;

use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Models\Organization;

/**
 * A made-up invoice in a country's form, for previewing an invoice template:
 * the seller and the invoice, neither stored. Its QR code says it is a
 * sample, so nothing a phone reads from it passes for an invoice.
 */
final readonly class PrintSample
{
    public function __construct(
        public Organization $seller,
        public Invoice $invoice,
    ) {}
}
