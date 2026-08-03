<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Thrown when XML cannot be parsed, or is rejected by a safety limit.
 *
 * Separate from the compliance exceptions: this says the bytes are not usable
 * XML at all, before any ZATCA or FTA rule has been considered.
 */
class XmlException extends RuntimeException
{
    /**
     * @param list<string> $errors libxml diagnostics
     */
    public function __construct(string $message, public readonly array $errors = [])
    {
        parent::__construct($message);
    }

    /**
     * @param list<string> $errors
     */
    public static function cannotParse(array $errors): self
    {
        $detail = $errors === [] ? 'no diagnostics available' : implode('; ', array_slice($errors, 0, 3));

        return new self("XML could not be parsed: {$detail}", $errors);
    }

    public static function hasDoctype(): self
    {
        return new self(
            'XML containing a DOCTYPE declaration is rejected. Document type definitions '
            .'allow entity expansion, and no supported e-invoicing schema needs one.'
        );
    }

    public static function tooBig(int $bytes, int $limit): self
    {
        return new self("XML document of {$bytes} bytes exceeds the {$limit} byte limit.");
    }
}
