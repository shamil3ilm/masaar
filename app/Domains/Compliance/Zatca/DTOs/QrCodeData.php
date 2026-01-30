<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\DTOs;

/**
 * Data required for ZATCA QR code generation.
 */
final readonly class QrCodeData
{
    public function __construct(
        public string $sellerName,
        public string $vatNumber,
        public string $timestamp,      // ISO 8601 format
        public string $invoiceTotal,   // With VAT
        public string $vatTotal,
        public ?string $invoiceHash = null,           // Phase 2
        public ?string $signature = null,             // Phase 2
        public ?string $publicKey = null,             // Phase 2
        public ?string $certificateSignature = null,  // Phase 2
    ) {}

    /**
     * Create from invoice data.
     */
    public static function fromInvoice(
        string $sellerName,
        string $vatNumber,
        \DateTimeInterface $timestamp,
        float $total,
        float $vatAmount,
        ?string $hash = null,
    ): self {
        return new self(
            sellerName: $sellerName,
            vatNumber: $vatNumber,
            timestamp: $timestamp->format('Y-m-d\TH:i:s\Z'),
            invoiceTotal: number_format($total, 2, '.', ''),
            vatTotal: number_format($vatAmount, 2, '.', ''),
            invoiceHash: $hash,
        );
    }
}
