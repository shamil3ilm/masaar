<?php

declare(strict_types=1);

namespace App\Domains\Platform\DTOs;

/**
 * Narrows a platform console listing to one state and one organization.
 *
 * A null field applies no filter.
 */
final readonly class FilterData
{
    public function __construct(
        public ?string $state = null,
        public ?string $organizationId = null,
    ) {}

    /**
     * Build from raw query-string values.
     *
     * Anything other than a non-empty string, and "0", is treated as not
     * given, so a malformed parameter narrows nothing rather than reaching
     * the query as an array.
     */
    public static function fromQuery(mixed $state, mixed $organizationId): self
    {
        return new self(self::given($state), self::given($organizationId));
    }

    private static function given(mixed $value): ?string
    {
        return is_string($value) && $value !== '' && $value !== '0' ? $value : null;
    }
}
