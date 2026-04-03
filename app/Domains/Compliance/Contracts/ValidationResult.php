<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Contracts;

final readonly class ValidationResult
{
    public function __construct(
        public bool $valid,
        public array $errors,
        public array $warnings,
    ) {}

    public static function pass(array $warnings = []): self
    {
        return new self(valid: true, errors: [], warnings: $warnings);
    }

    public static function fail(array $errors, array $warnings = []): self
    {
        return new self(valid: false, errors: $errors, warnings: $warnings);
    }
}
