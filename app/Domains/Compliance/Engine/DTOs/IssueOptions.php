<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\DTOs;

/**
 * What a caller asks of one issuance.
 *
 * $file has no default on purpose. Filing is irreversible, and a default
 * would let a caller that simply forgot to mention it file on live taxpayer
 * data. Callers say what they want, and the language holds them to it.
 */
final readonly class IssueOptions
{
    /**
     * @param  bool  $file  file or send the invoice once it is issued
     * @param  string|null  $idempotencyKey  the caller's key, for a module whose filing honours one
     */
    public function __construct(
        public bool $file,
        public ?string $idempotencyKey = null,
    ) {}
}
