<?php

declare(strict_types=1);

namespace App\Domains\Organization\DTOs;

/**
 * A seller's registration and address, as given.
 *
 * Null means not given, not "clear it": an update that leaves a field out
 * keeps what is stored.
 */
final readonly class AddressFields
{
    public function __construct(
        public ?string $crNumber = null,
        public ?string $street = null,
        public ?string $buildingNumber = null,
        public ?string $additionalStreet = null,
        public ?string $district = null,
        public ?string $city = null,
        public ?string $postalCode = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input  Validated request input
     */
    public static function from(array $input): self
    {
        $text = fn (string $key): ?string => isset($input[$key]) && $input[$key] !== '' ? trim((string) $input[$key]) : null;

        return new self(
            crNumber: $text('cr_number'),
            street: $text('street'),
            buildingNumber: $text('building_number'),
            additionalStreet: $text('additional_street'),
            district: $text('district'),
            city: $text('city'),
            postalCode: $text('postal_code'),
        );
    }

    /**
     * The columns given, for an insert or update.
     *
     * @return array<string, string>
     */
    public function given(): array
    {
        return array_filter([
            'cr_number' => $this->crNumber,
            'street' => $this->street,
            'building_number' => $this->buildingNumber,
            'additional_street' => $this->additionalStreet,
            'district' => $this->district,
            'city' => $this->city,
            'postal_code' => $this->postalCode,
        ], fn (?string $value): bool => $value !== null);
    }
}
