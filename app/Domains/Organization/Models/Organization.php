<?php

declare(strict_types=1);

namespace App\Domains\Organization\Models;

use App\Domains\Auth\Models\User;
use App\Domains\Compliance\Countries\SaudiArabia\DTOs\AddressData;
use App\Domains\Invoice\Models\Invoice;
use App\Domains\Organization\Concerns\RecordsActors;
use App\Domains\Platform\Customization\Customizations;
use App\Domains\Platform\Customization\Level;
use App\Domains\Platform\Customization\Scope;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
    use RecordsActors;

    /** Named to match Branch and ComplianceProfile, which use the same values. */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    /**
     * Signed up and waiting for a platform admin: it may draft, and may not
     * onboard with ZATCA or issue until it is approved.
     */
    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'name',
        'name_ar',
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
        // Whether its prices include VAT unless an invoice says otherwise.
        'prices_include_vat',
        // What it says it is, for the defaults that suggests (BusinessTypes).
        'business_type',
    ];

    protected function casts(): array
    {
        return [
            'compliance_profile' => 'array',
            // The extra QR printed on invoices, by paper (InvoiceExtraQr).
            'invoice_qr' => 'array',
            'prices_include_vat' => 'boolean',
            'require_two_factor' => 'boolean',
            // One numbering series for every branch, instead of one per till.
            'shared_numbering' => 'boolean',
            'reviewed_at' => 'datetime',
            // When its free period ends, fixed at approval (FreePeriod).
            'free_until' => 'immutable_datetime',
            'suspended_at' => 'datetime',
        ];
    }

    /**
     * Every organization has head office's own unit, under its own id: what
     * it issues on before it has tills, and what a branch without a till of
     * its own issues on.
     */
    protected static function booted(): void
    {
        static::created(function (Organization $organization): void {
            EgsUnit::withoutTenantScope(fn () => (new EgsUnit)->forceFill([
                'id' => $organization->id,
                'org_id' => $organization->id,
            ])->save());
        });
    }

    /**
     * Whether its prices include VAT unless an invoice says otherwise: its
     * own choice, or the platform's (invoices.prices_include_vat). The column
     * keeps the organization's own; null there is "as the platform".
     */
    protected function pricesIncludeVat(): Attribute
    {
        return Attribute::get(fn (mixed $own): bool => (bool) $this->customized('invoices.prices_include_vat', $own));
    }

    /**
     * Whether everyone in it must sign in with a second factor: its own
     * choice, or the platform's - which wins when the platform locked it
     * (security.require_two_factor).
     */
    protected function requireTwoFactor(): Attribute
    {
        return Attribute::get(fn (mixed $own): bool => (bool) $this->customized('security.require_two_factor', $own));
    }

    private function customized(string $key, mixed $own): mixed
    {
        return app(Customizations::class)
            ->resolve($key, Scope::organization((string) $this->getKey()), [Level::Organization->value => $own])
            ->value;
    }

    /**
     * Users belonging to this organization.
     * Pivot contains role (admin, member) and membership status.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(Membership::class)
            ->withPivot(['role', 'status'])
            ->withTimestamps();
    }

    /**
     * The column other tables use to point here.
     *
     * Eloquent would derive organization_id from the class name. The column is
     * org_id, so stating it once here keeps hasMany, hasOne and belongsToMany
     * working by convention instead of each relation naming the key itself.
     */
    public function getForeignKey(): string
    {
        return 'org_id';
    }

    /**
     * Branches (EGS units) belonging to this organization.
     */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    /**
     * Its tills (EGS units), head office's own included.
     */
    public function units(): HasMany
    {
        return $this->hasMany(EgsUnit::class);
    }

    /**
     * Invoices belonging to this organization.
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Whether this organization may submit to ZATCA.
     *
     * InvoiceValidator and Submitter both consult this before a submission, so
     * it answers from whichever of three records exists: the Saudi compliance
     * profile, the flag onboarding writes to compliance_profile, or a branch
     * that finished onboarding.
     *
     * Suspension and revocation are decisions and outrank the other two. A
     * profile in any other state is silent rather than negative, so the answer
     * falls through to what onboarding recorded.
     */
    public function getZatcaOnboardedAttribute(): bool
    {
        // complianceProfiles() rather than complianceProfileFor(), which
        // filters to active profiles. A suspended profile has to be visible
        // here to refuse below; filtered out, it is indistinguishable from an
        // organization that has no profile at all.
        $profile = $this->complianceProfiles()
            ->where('jurisdiction', 'SA')
            ->first();

        if ($profile !== null) {
            if ($profile->isActive()) {
                return true;
            }

            // Stopping is a decision, and it outranks anything recorded
            // earlier. Waiting is not a decision, so it says nothing and the
            // question falls through to what onboarding actually recorded.
            if (in_array($profile->status, [
                ComplianceProfile::STATUS_SUSPENDED,
                ComplianceProfile::STATUS_REVOKED,
            ], true)) {
                return false;
            }
        }

        if ($this->compliance_profile['zatca_onboarded'] ?? false) {
            return true;
        }

        return $this->branches()->where('onboarding_status', Branch::STATUS_ACTIVE)->exists();
    }

    /**
     * Whether this organization is barred from submitting.
     *
     * SubmissionTracker asked for $organization->is_suspended, which is neither
     * a column nor an accessor. Eloquent answered null, the `?? false` beside it
     * turned that into "not suspended", and the check never once refused a
     * submission. The column is status, as it is on Branch and
     * ComplianceProfile.
     */
    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    public function isPendingReview(): bool
    {
        return $this->status === self::STATUS_PENDING_REVIEW;
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
        return $this->hasMany(ComplianceProfile::class);
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
     * Whether the seller details ZATCA requires are all here, in its shapes.
     *
     * The building number and district were not checked, and both are on
     * every invoice: BR-KSA-37 refuses a building number that is not four
     * digits, and BR-KSA-09 requires the district. An organization without
     * them onboarded, then had its invoices refused.
     */
    public function hasCompleteZatcaProfile(): bool
    {
        return $this->isValidVatNumber()
            && filled($this->street)
            && preg_match('/^\d{4}$/', (string) $this->building_number) === 1
            && filled($this->district)
            && filled($this->city)
            && preg_match('/^\d{5}$/', (string) $this->postal_code) === 1;
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

    /**
     * The seller name a document carries.
     *
     * This name reaches two places: the signed XML, and tag 1 of the ZATCA
     * QR. DocumentBuilder reads it for both, and its own comment explains why
     * they have to be byte-identical - so there is one method rather than two
     * expressions that could drift.
     *
     * Falls back to the Latin name, and that fallback is compliant rather than
     * merely tolerated. ZATCA's Guideline for Tax Invoicing and Records (v3,
     * May 2026) 4.2.1 requires every field to be completed in Arabic and then
     * exempts proper names: "ZATCA approves the use of Latin characters for
     * writing such names as part of the Arabic language requirements in the
     * tax invoice." A seller name is a proper name.
     *
     * The Arabic name is offered because the authority's own illustrative
     * invoices print the seller bilingually, and a seller registered under an
     * Arabic name should be able to put it on the document and in the QR.
     *
     * Where a country requires Arabic on its invoices, the platform now asks
     * for more than that floor: the Saudi module requires the Arabic name at
     * sign-up and on the company page (SellerRules, ArabicText, after the
     * E-Invoicing Detailed Guidelines p60 and p63). The fallback stays for an
     * organization registered before, so its documents still build.
     */
    public function documentName(): string
    {
        return $this->filledOr($this->name_ar, (string) $this->name);
    }

    /**
     * The first of these that actually has something in it.
     *
     * An empty string is not a name. A column that exists and holds '' would
     * otherwise pass a null check and put nothing on the invoice.
     */
    private function filledOr(?string $preferred, string $fallback): string
    {
        return trim((string) $preferred) !== '' ? (string) $preferred : $fallback;
    }
}
