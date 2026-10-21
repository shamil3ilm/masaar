<?php

declare(strict_types=1);

namespace App\Domains\Books\DTOs;

use Carbon\CarbonImmutable;

/**
 * A purchase invoice or credit note as typed, or read from a CSV row group,
 * before Purchases checks it. Amounts are strings as entered.
 */
final readonly class PurchaseEntry
{
    /**
     * @param  list<PurchaseLineEntry>  $lines
     */
    public function __construct(
        public string $supplierId,
        public string $supplierInvoiceNumber,
        public CarbonImmutable $invoiceDate,
        public string $currency,
        public array $lines,
        public ?string $exchangeRate = null,
        public string $deductibleShare = '100',
        public string $documentType = 'invoice',
        public ?string $originalId = null,
        public ?string $description = null,
        public ?CarbonImmutable $receivedOn = null,
        public ?CarbonImmutable $dueOn = null,
        public bool $paidLater = false,
    ) {}
}
