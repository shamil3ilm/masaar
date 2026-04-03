<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\DTOs;

/**
 * Address data for ZATCA invoices.
 *
 * Per UBL 2.1 and ZATCA specifications, postal address elements must appear
 * in this exact order:
 * 1. StreetName
 * 2. AdditionalStreetName
 * 3. BuildingNumber
 * 4. PlotIdentification
 * 5. CitySubdivisionName (district)
 * 6. CityName
 * 7. PostalZone
 * 8. CountrySubentity
 * 9. Country/IdentificationCode
 */
final readonly class AddressData
{
    public function __construct(
        public string $street,
        public ?string $additionalStreet = null,
        public ?string $buildingNumber = null,
        public ?string $plotIdentification = null,  // Plot/land identification
        public ?string $district = null,             // CitySubdivisionName
        public string $city = '',
        public string $postalCode = '',              // 5 digits required
        public ?string $countrySubentity = null,     // Region/State
        public string $countryCode = 'SA',
    ) {}

    /**
     * Create from array.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            street: $data['street'] ?? '',
            additionalStreet: $data['additional_street'] ?? null,
            buildingNumber: $data['building_number'] ?? null,
            plotIdentification: $data['plot_identification'] ?? null,
            district: $data['district'] ?? null,
            city: $data['city'] ?? '',
            postalCode: $data['postal_code'] ?? '',
            countrySubentity: $data['country_subentity'] ?? $data['region'] ?? null,
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
