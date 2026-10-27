<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Engine\DTOs;

/**
 * When a country lets a supplier take back the output tax on a sale its
 * customer never paid for, and what it must hold to do so: how long after
 * the supply, whether the customer must have been told, whether an
 * accountant must certify the write-off, whether the customer must not be
 * related, and above what a customer owes formal recovery must have failed.
 * The tax must already have been declared on a return the supplier filed.
 */
final readonly class BadDebtRules
{
    public function __construct(
        public int $months,
        public bool $needsNotice,
        public bool $needsCertificate,
        public bool $needsUnrelated,
        public ?float $evidenceAbove,
        public string $citation,
    ) {}
}
