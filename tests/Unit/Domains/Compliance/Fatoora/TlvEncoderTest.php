<?php

use App\Domains\Compliance\Fatoora\Services\TlvEncoder;

it('encodes single TLV tag correctly', function () {
    $encoder = new TlvEncoder;

    $result = $encoder->encodeTag(1, 'Test');

    // Tag 1 + Length 4 + "Test"
    expect($result)->toBe(chr(1).chr(4).'Test');
});

it('encodes multiple fields to base64', function () {
    $encoder = new TlvEncoder;

    $result = $encoder->encode([
        1 => 'Seller',
        2 => '123456789',
    ]);

    expect($result)->toBeString();
    expect(base64_decode($result))->toContain('Seller');
    expect(base64_decode($result))->toContain('123456789');
});

it('decodes base64 TLV back to fields', function () {
    $encoder = new TlvEncoder;

    $original = [
        1 => 'Test Seller',
        2 => '300000000000003',
        3 => '2024-01-15T10:30:00Z',
        4 => '1150.00',
        5 => '150.00',
    ];

    $encoded = $encoder->encode($original);
    $decoded = $encoder->decode($encoded);

    expect($decoded)->toBe($original);
});
