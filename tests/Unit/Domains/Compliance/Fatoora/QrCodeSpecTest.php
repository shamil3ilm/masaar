<?php

declare(strict_types=1);

/**
 * ZATCA QR code compliance tests.
 *
 * Verifies TLV encoding against official ZATCA QR code specification:
 * "E-Invoicing Encoding Standard — Annex 2: QR Code Technical Specification"
 *
 * Key rules verified:
 * - Phase 1 (simplified / B2C): exactly 5 TLV tags
 * - Phase 2 (standard / B2B clearance): exactly 9 TLV tags
 * - Tags 1-5 are UTF-8 text; Tags 6-9 are raw binary bytes
 * - Timestamp must be ISO 8601 with UTC offset (Z)
 * - invoiceTotal is the tax-inclusive amount (BT-112)
 * - Phase 2 throws when any of tags 6-9 is absent
 * - TLV wire format: 1-byte tag + 1-byte length + value bytes
 * - Entire blob is base64-encoded for QR scanning
 */

use App\Domains\Compliance\Fatoora\DTOs\QrCodeData;
use App\Domains\Compliance\Fatoora\Services\QrCodeGenerator;
use App\Domains\Compliance\Fatoora\Services\TlvEncoder;

// ---------------------------------------------------------------------------
// Phase 1 — B2C (simplified invoice), 5 tags only
// ---------------------------------------------------------------------------

it('Phase 1 QR encodes exactly 5 TLV tags per ZATCA spec', function () {
    $encoder = new TlvEncoder;
    $generator = new QrCodeGenerator($encoder);

    $data = new QrCodeData(
        sellerName: 'شركة الاختبار',   // Arabic seller name
        vatNumber: '300000000000003',
        timestamp: '2024-11-15T10:30:00Z',
        invoiceTotal: '1150.00',
        vatTotal: '150.00',
    );

    $decoded = $encoder->decode($generator->generatePhase1($data));

    expect($decoded)->toHaveCount(5);
    expect($decoded)->toHaveKeys([1, 2, 3, 4, 5]);
    expect($decoded)->not->toHaveKey(6); // No hash in Phase 1
});

it('Phase 1 tag 1 contains the seller name exactly', function () {
    $encoder = new TlvEncoder;
    $generator = new QrCodeGenerator($encoder);

    $data = new QrCodeData(
        sellerName: 'Acme KSA LLC',
        vatNumber: '300000000000003',
        timestamp: '2024-11-15T10:30:00Z',
        invoiceTotal: '575.00',
        vatTotal: '75.00',
    );

    $decoded = $encoder->decode($generator->generatePhase1($data));

    expect($decoded[1])->toBe('Acme KSA LLC');
});

it('Phase 1 tag 2 contains the 15-digit VAT registration number', function () {
    $encoder = new TlvEncoder;
    $generator = new QrCodeGenerator($encoder);

    $vatNumber = '300000000000003';
    $data = new QrCodeData(
        sellerName: 'Seller',
        vatNumber: $vatNumber,
        timestamp: '2024-11-15T10:30:00Z',
        invoiceTotal: '115.00',
        vatTotal: '15.00',
    );

    $decoded = $encoder->decode($generator->generatePhase1($data));

    expect($decoded[2])->toBe($vatNumber);
    expect(strlen($decoded[2]))->toBe(15);
    expect($decoded[2][0])->toBe('3'); // Must start with 3 per BR-KSA-14
});

it('Phase 1 tag 3 contains an ISO 8601 timestamp with UTC marker', function () {
    $encoder = new TlvEncoder;
    $generator = new QrCodeGenerator($encoder);

    $timestamp = '2024-11-15T10:30:00Z';
    $data = new QrCodeData(
        sellerName: 'Seller',
        vatNumber: '300000000000003',
        timestamp: $timestamp,
        invoiceTotal: '115.00',
        vatTotal: '15.00',
    );

    $decoded = $encoder->decode($generator->generatePhase1($data));

    expect($decoded[3])->toBe($timestamp);
    // ISO 8601 UTC: ends with 'Z'
    expect(str_ends_with($decoded[3], 'Z'))->toBeTrue();
});

it('Phase 1 tag 4 is the tax-inclusive invoice total (BT-112)', function () {
    $encoder = new TlvEncoder;
    $generator = new QrCodeGenerator($encoder);

    $data = new QrCodeData(
        sellerName: 'Seller',
        vatNumber: '300000000000003',
        timestamp: '2024-11-15T10:30:00Z',
        invoiceTotal: '1150.00', // Includes the 150 VAT — tax-inclusive per spec
        vatTotal: '150.00',
    );

    $decoded = $encoder->decode($generator->generatePhase1($data));

    expect($decoded[4])->toBe('1150.00');
});

it('Phase 1 tag 5 is the VAT total (BT-110)', function () {
    $encoder = new TlvEncoder;
    $generator = new QrCodeGenerator($encoder);

    $data = new QrCodeData(
        sellerName: 'Seller',
        vatNumber: '300000000000003',
        timestamp: '2024-11-15T10:30:00Z',
        invoiceTotal: '1150.00',
        vatTotal: '150.00',
    );

    $decoded = $encoder->decode($generator->generatePhase1($data));

    expect($decoded[5])->toBe('150.00');
});

it('Phase 1 QR returns valid base64', function () {
    $encoder = new TlvEncoder;
    $generator = new QrCodeGenerator($encoder);

    $data = new QrCodeData(
        sellerName: 'Seller',
        vatNumber: '300000000000003',
        timestamp: '2024-11-15T10:30:00Z',
        invoiceTotal: '1150.00',
        vatTotal: '150.00',
    );

    $qr = $generator->generatePhase1($data);

    expect(base64_decode($qr, strict: true))->not->toBeFalse();
});

// ---------------------------------------------------------------------------
// Phase 2 — B2B (standard invoice), 9 tags including cryptographic fields
// ---------------------------------------------------------------------------

it('Phase 2 QR encodes exactly 9 TLV tags per ZATCA spec', function () {
    $encoder = new TlvEncoder;
    $generator = new QrCodeGenerator($encoder);

    // Tags 6-9 are raw binary bytes in practice; using ASCII stand-ins here
    $data = new QrCodeData(
        sellerName: 'Acme KSA LLC',
        vatNumber: '300000000000003',
        timestamp: '2024-11-15T10:30:00Z',
        invoiceTotal: '1150.00',
        vatTotal: '150.00',
        invoiceHash: random_bytes(32),        // Tag 6: 32-byte SHA-256 raw hash
        signature: random_bytes(64),           // Tag 7: ECDSA DER-encoded signature (~72 bytes typical)
        publicKey: random_bytes(33),           // Tag 8: EC compressed public key (33 bytes)
        certificateSignature: random_bytes(32), // Tag 9: ZATCA CA signature
    );

    $decoded = $encoder->decode($generator->generatePhase2($data));

    expect($decoded)->toHaveCount(9);
    expect($decoded)->toHaveKeys([1, 2, 3, 4, 5, 6, 7, 8, 9]);
});

it('Phase 2 tag 6 carries the raw binary invoice hash', function () {
    $encoder = new TlvEncoder;
    $generator = new QrCodeGenerator($encoder);

    $rawHash = random_bytes(32); // 32 raw bytes — not base64 at this stage

    $data = new QrCodeData(
        sellerName: 'Seller',
        vatNumber: '300000000000003',
        timestamp: '2024-11-15T10:30:00Z',
        invoiceTotal: '1150.00',
        vatTotal: '150.00',
        invoiceHash: $rawHash,
        signature: random_bytes(64),
        publicKey: random_bytes(33),
        certificateSignature: random_bytes(32),
    );

    $decoded = $encoder->decode($generator->generatePhase2($data));

    // Tag 6 must round-trip as the same raw bytes (not re-encoded)
    expect($decoded[6])->toBe($rawHash);
    expect(strlen($decoded[6]))->toBe(32);
});

it('Phase 2 throws when invoice hash (tag 6) is absent', function () {
    $encoder = new TlvEncoder;
    $generator = new QrCodeGenerator($encoder);

    $data = new QrCodeData(
        sellerName: 'Seller',
        vatNumber: '300000000000003',
        timestamp: '2024-11-15T10:30:00Z',
        invoiceTotal: '1150.00',
        vatTotal: '150.00',
        invoiceHash: null, // Missing — must throw
        signature: random_bytes(64),
        publicKey: random_bytes(33),
        certificateSignature: random_bytes(32),
    );

    expect(fn () => $generator->generatePhase2($data))
        ->toThrow(InvalidArgumentException::class);
});

it('Phase 2 throws when ECDSA signature (tag 7) is absent', function () {
    $encoder = new TlvEncoder;
    $generator = new QrCodeGenerator($encoder);

    $data = new QrCodeData(
        sellerName: 'Seller',
        vatNumber: '300000000000003',
        timestamp: '2024-11-15T10:30:00Z',
        invoiceTotal: '1150.00',
        vatTotal: '150.00',
        invoiceHash: random_bytes(32),
        signature: null, // Missing — must throw
        publicKey: random_bytes(33),
        certificateSignature: random_bytes(32),
    );

    expect(fn () => $generator->generatePhase2($data))
        ->toThrow(InvalidArgumentException::class);
});

it('Phase 2 throws when public key (tag 8) is absent', function () {
    $encoder = new TlvEncoder;
    $generator = new QrCodeGenerator($encoder);

    $data = new QrCodeData(
        sellerName: 'Seller',
        vatNumber: '300000000000003',
        timestamp: '2024-11-15T10:30:00Z',
        invoiceTotal: '1150.00',
        vatTotal: '150.00',
        invoiceHash: random_bytes(32),
        signature: random_bytes(64),
        publicKey: null, // Missing — must throw
        certificateSignature: random_bytes(32),
    );

    expect(fn () => $generator->generatePhase2($data))
        ->toThrow(InvalidArgumentException::class);
});

it('Phase 2 throws when ZATCA CA certificate signature (tag 9) is absent', function () {
    $encoder = new TlvEncoder;
    $generator = new QrCodeGenerator($encoder);

    $data = new QrCodeData(
        sellerName: 'Seller',
        vatNumber: '300000000000003',
        timestamp: '2024-11-15T10:30:00Z',
        invoiceTotal: '1150.00',
        vatTotal: '150.00',
        invoiceHash: random_bytes(32),
        signature: random_bytes(64),
        publicKey: random_bytes(33),
        certificateSignature: null, // Missing — must throw
    );

    expect(fn () => $generator->generatePhase2($data))
        ->toThrow(InvalidArgumentException::class);
});

// ---------------------------------------------------------------------------
// TLV wire format correctness
// ---------------------------------------------------------------------------

it('TLV tag byte encodes as a single byte followed by length then value', function () {
    $encoder = new TlvEncoder;

    // "ArabCo" = 6 bytes
    $tlv = $encoder->encodeTag(1, 'ArabCo');

    expect(ord($tlv[0]))->toBe(1);          // Tag byte
    expect(ord($tlv[1]))->toBe(6);          // Length byte: strlen('ArabCo') = 6
    expect(substr($tlv, 2))->toBe('ArabCo'); // Value bytes
    expect(strlen($tlv))->toBe(8);           // 1 + 1 + 6 = 8 bytes total
});

it('TLV encodes multi-byte UTF-8 seller name by byte length, not character count', function () {
    $encoder = new TlvEncoder;

    // Arabic text: each character is 2 bytes in UTF-8
    $arabicName = 'شركة'; // 4 Arabic chars = 8 UTF-8 bytes
    $tlv = $encoder->encodeTag(1, $arabicName);

    $byteLength = strlen($arabicName); // PHP strlen returns byte count
    expect(ord($tlv[1]))->toBe($byteLength);
});

it('TLV round-trip preserves all 9 Phase 2 fields exactly', function () {
    $encoder = new TlvEncoder;

    $sellerName = 'شركة الاختبار ذ.م.م';
    $vatNumber = '300000000000003';
    $timestamp = '2024-11-15T10:30:00Z';
    $total = '11500.00';
    $vat = '1500.00';
    $hash = random_bytes(32);
    $sig = random_bytes(71);
    $pubKey = random_bytes(65); // Uncompressed EC public key = 65 bytes
    $caSig = random_bytes(32);

    $fields = [
        1 => $sellerName,
        2 => $vatNumber,
        3 => $timestamp,
        4 => $total,
        5 => $vat,
        6 => $hash,
        7 => $sig,
        8 => $pubKey,
        9 => $caSig,
    ];

    $encoded = $encoder->encode($fields);
    $decoded = $encoder->decode($encoded);

    expect($decoded)->toBe($fields);
});

it('QrCodeData::fromInvoice formats total to 2 decimal places', function () {
    $data = QrCodeData::fromInvoice(
        sellerName: 'Seller',
        vatNumber: '300000000000003',
        timestamp: new DateTime('2024-11-15 10:30:00'),
        total: 1150.5,
        vatAmount: 150.0,
    );

    expect($data->invoiceTotal)->toBe('1150.50');
    expect($data->vatTotal)->toBe('150.00');
});

it('QrCodeData::fromInvoice timestamp uses Z UTC suffix per spec', function () {
    $dt = new DateTime('2024-11-15 10:30:00', new DateTimeZone('UTC'));

    $data = QrCodeData::fromInvoice(
        sellerName: 'Seller',
        vatNumber: '300000000000003',
        timestamp: $dt,
        total: 1150.00,
        vatAmount: 150.00,
    );

    expect($data->timestamp)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
});
