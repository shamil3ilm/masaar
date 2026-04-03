<?php

use App\Domains\Compliance\Fatoora\DTOs\QrCodeData;
use App\Domains\Compliance\Fatoora\Services\QrCodeGenerator;
use App\Domains\Compliance\Fatoora\Services\TlvEncoder;

it('generates Phase 1 QR code with 5 tags', function () {
    $encoder = new TlvEncoder();
    $generator = new QrCodeGenerator($encoder);

    $data = new QrCodeData(
        sellerName: 'Test Company',
        vatNumber: '300000000000003',
        timestamp: '2024-01-15T10:30:00Z',
        invoiceTotal: '1150.00',
        vatTotal: '150.00',
    );

    $qrCode = $generator->generatePhase1($data);

    // Decode and verify
    $decoded = $encoder->decode($qrCode);

    expect($decoded[1])->toBe('Test Company');
    expect($decoded[2])->toBe('300000000000003');
    expect($decoded[3])->toBe('2024-01-15T10:30:00Z');
    expect($decoded[4])->toBe('1150.00');
    expect($decoded[5])->toBe('150.00');
    expect($decoded)->not->toHaveKey(6); // No hash in Phase 1
});

it('generates Phase 2 QR code with hash', function () {
    $encoder = new TlvEncoder();
    $generator = new QrCodeGenerator($encoder);

    // Phase 2 requires all cryptographic fields (tags 6-9)
    $data = new QrCodeData(
        sellerName: 'Test Company',
        vatNumber: '300000000000003',
        timestamp: '2024-01-15T10:30:00Z',
        invoiceTotal: '1150.00',
        vatTotal: '150.00',
        invoiceHash: 'abc123hash',
        signature: 'MEUCIQDtest123signature', // Tag 7
        publicKey: 'BHTestPublicKey123', // Tag 8
        certificateSignature: 'MIIBtest456signature', // Tag 9
    );

    $qrCode = $generator->generatePhase2($data);
    $decoded = $encoder->decode($qrCode);

    expect($decoded[6])->toBe('abc123hash');
    expect($decoded[7])->toBe('MEUCIQDtest123signature');
    expect($decoded[8])->toBe('BHTestPublicKey123');
    expect($decoded[9])->toBe('MIIBtest456signature');
});

it('creates QrCodeData from invoice data', function () {
    $timestamp = new \DateTime('2024-01-15 10:30:00');

    $data = QrCodeData::fromInvoice(
        sellerName: 'Test Seller',
        vatNumber: '123456789',
        timestamp: $timestamp,
        total: 1150.00,
        vatAmount: 150.00,
    );

    expect($data->sellerName)->toBe('Test Seller');
    expect($data->vatNumber)->toBe('123456789');
    expect($data->invoiceTotal)->toBe('1150.00');
    expect($data->vatTotal)->toBe('150.00');
});
