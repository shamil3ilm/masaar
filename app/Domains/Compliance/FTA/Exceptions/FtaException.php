<?php

declare(strict_types=1);

namespace App\Domains\Compliance\FTA\Exceptions;

class FtaException extends \RuntimeException
{
    public static function submissionFailed(string $message, ?string $raw = null): self
    {
        $e = new self("UAE FTA submission failed: {$message}");
        $e->rawResponse = $raw;
        return $e;
    }

    public static function invalidTrn(string $trn): self
    {
        return new self("Invalid UAE TRN format: '{$trn}'. Must be exactly 15 digits.");
    }

    public static function invalidState(string $current, string $attempted): self
    {
        return new self("Cannot transition UAE FTA submission from '{$current}' to '{$attempted}'.");
    }

    public ?string $rawResponse = null;
}
