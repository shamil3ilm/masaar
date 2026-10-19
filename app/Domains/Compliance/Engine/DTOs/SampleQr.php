<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\DTOs;

/**
 * A sample invoice's QR code, as its country encodes it.
 *
 * payload    what the code holds, exactly as a phone reads it
 * encoding   how the payload's bytes are read (QrCodeRenderer's $encoding)
 * taxNumber  the seller's tax number the payload carries: a sample one, of
 *            the country's form, that no real seller holds
 */
final readonly class SampleQr
{
    public function __construct(
        public string $payload,
        public string $encoding,
        public string $taxNumber,
    ) {}
}
