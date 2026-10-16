<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Peppol\DTOs;

/**
 * A postal address as PINT carries it. Street, city and subdivision are all
 * required on both parties in the UAE (IBR-143-AE, IBR-144-AE); for an
 * address in the UAE the subdivision is the emirate's code.
 */
final readonly class PintAddress
{
    public function __construct(
        public string $street,
        public string $city,
        public string $subdivision,
        public string $countryCode,
        public ?string $additionalStreet = null,
        public ?string $postalCode = null,
    ) {}
}
