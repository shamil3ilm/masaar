<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\Services;

use App\Domains\Compliance\Zatca\DTOs\QrCodeData;

/**
 * ZATCA QR code generator.
 *
 * Generates TLV-encoded base64 QR code data as per ZATCA Phase 1 & 2 specs.
 *
 * Tags:
 * 1 = Seller name
 * 2 = VAT number
 * 3 = Timestamp (ISO 8601)
 * 4 = Invoice total (with VAT)
 * 5 = VAT total
 * 6 = Invoice hash (Phase 2)
 * 7 = ECDSA signature (Phase 2)
 * 8 = Public key (Phase 2)
 * 9 = ZATCA CA signature (Phase 2)
 */
class QrCodeGenerator
{
    public function __construct(
        private readonly TlvEncoder $encoder
    ) {}

    /**
     * Generate Phase 1 QR code (5 tags).
     * Used for simplified invoices (B2C).
     */
    public function generatePhase1(QrCodeData $data): string
    {
        return $this->encoder->encode([
            1 => $data->sellerName,
            2 => $data->vatNumber,
            3 => $data->timestamp,
            4 => $data->invoiceTotal,
            5 => $data->vatTotal,
        ]);
    }

    /**
     * Generate Phase 2 QR code (9 tags).
     * Used for standard invoices (B2B) requiring clearance.
     */
    public function generatePhase2(QrCodeData $data): string
    {
        $fields = [
            1 => $data->sellerName,
            2 => $data->vatNumber,
            3 => $data->timestamp,
            4 => $data->invoiceTotal,
            5 => $data->vatTotal,
            6 => $data->invoiceHash ?? '',
        ];

        // Phase 2 cryptographic fields (optional for now)
        if ($data->signature !== null) {
            $fields[7] = $data->signature;
        }
        if ($data->publicKey !== null) {
            $fields[8] = $data->publicKey;
        }
        if ($data->certificateSignature !== null) {
            $fields[9] = $data->certificateSignature;
        }

        return $this->encoder->encode($fields);
    }
}
