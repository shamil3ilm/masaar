<?php

declare(strict_types=1);

namespace App\Domains\Organization\DTOs;

/**
 * Validated changes to an organization.
 *
 * A null name keeps the current one; a null or blank VAT number keeps the
 * current one.
 */
final readonly class OrganizationChangesData
{
    public function __construct(
        public ?string $name = null,
        public ?string $vatNumber = null,
    ) {}
}
