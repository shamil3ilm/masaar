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

    /**
     * Named rather than folded into invalidState(), because the caller has
     * not asked for a transition - it has asked to submit an invoice that is
     * already with the authority, and the remedy is to retry the existing
     * submission rather than to create another.
     */
    public static function alreadySubmitted(string $invoiceNumber, string $state): self
    {
        return new self(
            "Invoice {$invoiceNumber} has already been submitted to the UAE FTA and is '{$state}'. "
            .'Retry that submission rather than creating a second one.'
        );
    }

    public static function invalidState(string $current, string $attempted): self
    {
        return new self("Cannot transition UAE FTA submission from '{$current}' to '{$attempted}'.");
    }

    public ?string $rawResponse = null;
}
