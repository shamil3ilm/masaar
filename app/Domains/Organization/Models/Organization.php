<?php

declare(strict_types=1);

namespace App\Domains\Organization\Models;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Fatoora\DTOs\AddressData;
use App\Domains\Invoice\Models\Invoice;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'group_id',
        'compliance_profile',
        // Required by ZATCA for seller BT-31 and QR tag 2. The onboarding API
        // accepts it, so it has to be mass-assignable or it is silently lost.
        'vat_number',
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
     * Branches (EGS units) belonging to this organization.
     */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class, 'org_id');
    }

    /**
     * Get active branches ready for invoicing.
     */
    public function activeBranches(): HasMany
    {
        return $this->branches()->fatooraReady();
    }

    /**
     * Get the default branch.
     */
    public function defaultBranch(): ?Branch
    {
        return $this->branches()->where('is_default', true)->first();
    }

    /**
     * Invoices belonging to this organization.
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'org_id');
    }

    /**
     * Check if organization has completed ZATCA onboarding.
     *
     * @deprecated Use complianceProfileFor('SA')->isActive() instead.
     */
    public function getZatcaOnboardedAttribute(): bool
    {
        $profile = $this->complianceProfileFor('SA');

        if ($profile !== null) {
            return $profile->isActive();
        }

        // Legacy fallback
        if ($this->compliance_profile['zatca_onboarded'] ?? false) {
            return true;
        }

        return $this->branches()->where('onboarding_status', Branch::STATUS_ACTIVE)->exists();
    }

    /**
     * Get VAT number — prefers active ComplianceProfile, falls back to legacy JSON.
     */
    /**
     * The organization's VAT registration number.
     *
     * A jurisdiction's compliance profile wins, because one organization can
     * hold different registrations in KSA and the UAE. The stored column is
     * the fallback, and it is the value the onboarding API writes.
     *
     * Without that fallback the column is unreachable: ZATCA needs this for
     * seller BT-31 and QR tag 2, and reading null there makes submission
     * impossible for every organization that has no profile row.
     */
    public function getVatNumberAttribute(): ?string
    {
        $profile = $this->complianceProfileFor($this->country ?? 'SA');

        return $profile?->setting('vat_number')
            ?? $this->compliance_profile['vat_number']
            ?? $this->attributes['vat_number']
            ?? null;
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
     * The optional holding group this organization belongs to.
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(OrganizationGroup::class, 'group_id');
    }

    /**
     * All compliance profiles for this organization (one per jurisdiction).
     */
    public function complianceProfiles(): HasMany
    {
        return $this->hasMany(ComplianceProfile::class, 'org_id');
    }

    /**
     * Get the active compliance profile for a given jurisdiction.
     */
    public function complianceProfileFor(string $jurisdiction): ?ComplianceProfile
    {
        return $this->complianceProfiles()
            ->where('jurisdiction', $jurisdiction)
            ->where('status', ComplianceProfile::STATUS_ACTIVE)
            ->first();
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
     * Must be 15 digits starting AND ending with 3 per ZATCA requirements.
     */
    public function isValidVatNumber(): bool
    {
        $vat = $this->vat_number;

        // Saudi VAT numbers must be 15 digits, start with 3, and end with 3
        return $vat !== null
            && strlen($vat) === 15
            && ctype_digit($vat)
            && str_starts_with($vat, '3')
            && str_ends_with($vat, '3');
    }
}
