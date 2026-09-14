<?php

declare(strict_types=1);

namespace App\Domains\Organization\DTOs;

/**
 * Validated input for founding an organization.
 */
final readonly class OrganizationData
{
    public function __construct(
        public string $name,
        public string $country,
        public ?string $vatNumber,
    ) {}
}
