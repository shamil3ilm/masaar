<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\DTOs;

/**
 * Which submissions a listing should show.
 *
 * A null field applies no filter.
 */
final readonly class SubmissionFilterData
{
    public function __construct(
        public ?string $userId = null,
        public ?string $state = null,
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
    ) {}

    /**
     * Build from raw query-string values.
     *
     * Anything other than a non-empty string, and "0", is treated as not
     * given, so a malformed parameter narrows nothing rather than reaching
     * the query as an array.
     */
    public static function fromQuery(mixed $userId, mixed $state, mixed $dateFrom, mixed $dateTo): self
    {
        return new self(self::given($userId), self::given($state), self::given($dateFrom), self::given($dateTo));
    }

    private static function given(mixed $value): ?string
    {
        return is_string($value) && $value !== '' && $value !== '0' ? $value : null;
    }
}
