<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\DTOs;

/**
 * Data required for ZATCA QR code generation.
 *
 * TLV Tag Structure:
 * - Tags 1-5: UTF-8 text strings
 * - Tags 6-9: RAW BINARY BYTES (not base64 encoded)
 *
 * The TlvEncoder handles converting this to the final base64 QR string.
 */
final readonly class QrCodeData
{
    public function __construct(
        public string $sellerName,                    // Tag 1: UTF-8 text
        public string $vatNumber,                     // Tag 2: UTF-8 text
        public string $timestamp,                     // Tag 3: ISO 8601 format
        public string $invoiceTotal,                  // Tag 4: Decimal string with VAT
        public string $vatTotal,                      // Tag 5: Decimal string
        public ?string $invoiceHash = null,           // Tag 6: RAW bytes (SHA-256 hash)
        public ?string $signature = null,             // Tag 7: RAW bytes (ECDSA signature)
        public ?string $publicKey = null,             // Tag 8: RAW bytes (EC public key)
        public ?string $certificateSignature = null,  // Tag 9: RAW bytes (CA signature)
    ) {}

    /**
     * Create from invoice data (Phase 1 only - basic 5 tags).
     *
     * For Phase 2 invoices, use the constructor directly with all
     * cryptographic fields (hash, signature, publicKey, certificateSignature).
     *
     * @param string $sellerName Seller's legal name
     * @param string $vatNumber Seller's VAT registration number
     * @param \DateTimeInterface $timestamp Invoice issue date/time
     * @param float $total Invoice total including VAT
     * @param float $vatAmount Total VAT amount
     * @param string|null $hash RAW bytes of SHA-256 hash (not base64)
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
