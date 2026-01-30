<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\DTOs;

/**
 * Data required for ZATCA invoice XML generation.
 */
final readonly class InvoiceXmlData
{
    /**
     * @param array<int, array{description: string, quantity: float, unitPrice: float, lineTotal: float}> $lines
     */
    public function __construct(
        public string $uuid,
        public string $invoiceNumber,
        public string $issueDate,              // YYYY-MM-DD
        public string $issueTime,              // HH:MM:SS
        public string $invoiceTypeCode,        // 388 = standard, 381 = credit note
        public string $currency,
        public string $sellerName,
        public string $sellerVatNumber,
        public string $buyerName,
        public ?string $buyerVatNumber,
        public float $subtotal,
        public float $taxAmount,
        public float $total,
        public array $lines,
        public ?string $previousInvoiceHash = null,
    ) {}
}
