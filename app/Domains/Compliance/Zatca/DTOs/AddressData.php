<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Zatca\DTOs;

/**
 * Address data for ZATCA invoices.
 */
final readonly class AddressData
{
    public function __construct(
        public string $street,
        public ?string $buildingNumber = null,
        public ?string $additionalStreet = null,
        public string $city = '',
        public string $postalCode = '',           // 5 digits required
        public ?string $district = null,
        public string $countryCode = 'SA',
    ) {}

    /**
     * Create from array.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            street: $data['street'] ?? '',
            buildingNumber: $data['building_number'] ?? null,
            additionalStreet: $data['additional_street'] ?? null,
            city: $data['city'] ?? '',
            postalCode: $data['postal_code'] ?? '',
            district: $data['district'] ?? null,
            countryCode: $data['country_code'] ?? 'SA',
        );
    }

    /**
     * Validate postal code format (5 digits).
     */
    public function isValidPostalCode(): bool
    {
        return preg_match('/^\d{5}$/', $this->postalCode) === 1;
    }
}
