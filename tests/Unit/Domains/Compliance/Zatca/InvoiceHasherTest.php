<?php

use App\Domains\Compliance\Zatca\Services\InvoiceHasher;

it('generates consistent hash for same XML', function () {
    $hasher = new InvoiceHasher();

    $xml = '<Invoice><ID>INV-001</ID></Invoice>';

    $hash1 = $hasher->hash($xml);
    $hash2 = $hasher->hash($xml);

    expect($hash1)->toBe($hash2);
});

it('generates different hash for different XML', function () {
    $hasher = new InvoiceHasher();

    $xml1 = '<Invoice><ID>INV-001</ID></Invoice>';
    $xml2 = '<Invoice><ID>INV-002</ID></Invoice>';

    expect($hasher->hash($xml1))->not->toBe($hasher->hash($xml2));
});

it('returns base64 encoded hash', function () {
    $hasher = new InvoiceHasher();

    $xml = '<Invoice><ID>INV-001</ID></Invoice>';
    $hash = $hasher->hash($xml);

    // Should be valid base64
    expect(base64_decode($hash, true))->not->toBeFalse();
});

it('verifies hash correctly', function () {
    $hasher = new InvoiceHasher();

    $xml = '<Invoice><ID>INV-001</ID></Invoice>';
    $hash = $hasher->hash($xml);

    expect($hasher->verify($xml, $hash))->toBeTrue();
    expect($hasher->verify($xml, 'wronghash'))->toBeFalse();
});

it('normalizes XML whitespace for consistent hashing', function () {
    $hasher = new InvoiceHasher();

    $xml1 = '<Invoice><ID>INV-001</ID></Invoice>';
    $xml2 = "<Invoice>\n  <ID>INV-001</ID>\n</Invoice>";

    // After normalization, should produce same hash
    expect($hasher->hash($xml1))->toBe($hasher->hash($xml2));
});
