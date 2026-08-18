<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Services;

use App\Domains\Compliance\Fatoora\DTOs\QrCodeData;
use InvalidArgumentException;

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
 * 7 = ECDSA signature (Phase 2, mandatory for B2B)
 * 8 = Public key (Phase 2, mandatory for B2B)
 * 9 = ZATCA CA signature (Phase 2, mandatory for B2B)
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
        $this->validateBasicFields($data);

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
     *
     * @throws InvalidArgumentException If mandatory cryptographic fields are missing
     */
    public function generatePhase2(QrCodeData $data): string
    {
        $this->validateBasicFields($data);
        $this->validatePhase2Fields($data);

        return $this->encoder->encode([
            1 => $data->sellerName,
            2 => $data->vatNumber,
            3 => $data->timestamp,
            4 => $data->invoiceTotal,
            5 => $data->vatTotal,
            6 => $data->invoiceHash,
            7 => $data->signature,
            8 => $data->publicKey,
            9 => $data->certificateSignature,
        ]);
    }

    /**
     * Validate basic QR code fields (tags 1-5).
     */
    private function validateBasicFields(QrCodeData $data): void
    {
        $errors = [];

        if (empty($data->sellerName)) {
            $errors[] = 'Seller name (tag 1) is required';
        }
        if (empty($data->vatNumber)) {
            $errors[] = 'VAT number (tag 2) is required';
        }
        if (empty($data->timestamp)) {
            $errors[] = 'Timestamp (tag 3) is required';
        }
        if (empty($data->invoiceTotal)) {
            $errors[] = 'Invoice total (tag 4) is required';
        }
        if (empty($data->vatTotal) && $data->vatTotal !== '0.00') {
            $errors[] = 'VAT total (tag 5) is required';
        }

        if (! empty($errors)) {
            throw new InvalidArgumentException('QR code validation failed: '.implode(', ', $errors));
        }
    }

    /**
     * Validate Phase 2 cryptographic fields (tags 6-9).
     * These are mandatory for B2B invoices requiring clearance.
     */
    private function validatePhase2Fields(QrCodeData $data): void
    {
        $errors = [];

        if (empty($data->invoiceHash)) {
            $errors[] = 'Invoice hash (tag 6) is required for Phase 2';
        }
        if (empty($data->signature)) {
            $errors[] = 'ECDSA signature (tag 7) is required for Phase 2';
        }
        if (empty($data->publicKey)) {
            $errors[] = 'Public key (tag 8) is required for Phase 2';
        }
        if (empty($data->certificateSignature)) {
            $errors[] = 'Certificate signature (tag 9) is required for Phase 2';
        }

        if (! empty($errors)) {
            throw new InvalidArgumentException(
                'Phase 2 QR code requires cryptographic fields for B2B clearance: '.implode(', ', $errors)
            );
        }
    }
}
