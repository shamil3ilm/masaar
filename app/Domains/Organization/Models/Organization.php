<?php

declare(strict_types=1);

namespace App\Domains\Organization\Models;

use App\Domains\Compliance\Zatca\DTOs\AddressData;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Organization entity (tenant).
 *
 * Represents a company/tenant in the multi-org system.
 * ZATCA compliance is scoped per organization.
 */
class Organization extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'country',
        'status',
        'compliance_profile',
        // Address fields (ZATCA required)
        'street',
        'building_number',
        'additional_street',
        'district',
        'city',
        'postal_code',
        'cr_number',
    ];

    protected function casts(): array
    {
        return [
            'compliance_profile' => 'array',
        ];
    }

    /**
     * Users belonging to this organization.
     * Pivot contains role (admin, member) and membership status.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['role', 'status'])
            ->withTimestamps();
    }

    /**
     * Get VAT number from compliance profile.
     */
    public function getVatNumberAttribute(): ?string
    {
        return $this->compliance_profile['vat_number'] ?? null;
    }

    /**
     * Get address as AddressData DTO for ZATCA.
     */
    public function getAddressData(): AddressData
    {
        return new AddressData(
            street: $this->street ?? '',
            buildingNumber: $this->building_number,
            additionalStreet: $this->additional_street,
            city: $this->city ?? '',
            postalCode: $this->postal_code ?? '',
            district: $this->district,
            countryCode: $this->country,
        );
    }

    /**
     * Check if organization has complete ZATCA profile.
     */
    public function hasCompleteZatcaProfile(): bool
    {
        return $this->vat_number !== null
            && strlen($this->vat_number) === 15
            && $this->street !== null
            && $this->city !== null
            && $this->postal_code !== null
            && strlen($this->postal_code) === 5;
    }

    /**
     * Validate VAT number format.
     * Must be 15 digits starting with 3.
     */
    public function isValidVatNumber(): bool
    {
        $vat = $this->vat_number;

        return $vat !== null
            && strlen($vat) === 15
            && ctype_digit($vat)
            && str_starts_with($vat, '3');
    }
}
