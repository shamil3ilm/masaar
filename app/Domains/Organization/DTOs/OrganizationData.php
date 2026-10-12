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
        // The name a Saudi tax invoice is issued under. It reaches the signed
        // XML and tag 1 of the QR, so it is the most widely read field the
        // organization owns.
        public ?string $nameAr,
        public string $country,
        public ?string $vatNumber,
    ) {}
}
