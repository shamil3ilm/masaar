<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Support;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\PintAe;
use App\Domains\Organization\DTOs\AddressFields;
use App\Domains\Organization\DTOs\OrganizationChangesData;
use App\Domains\Organization\Models\ComplianceProfile;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\Registrar;
use Illuminate\Support\Facades\DB;

/**
 * Saves a UAE seller's details, validated by the module's SellerRules.
 *
 * The name and address go on the organization, as every country's do. The
 * UAE tax identity goes in the settings of the organization's UAE compliance
 * profile, which is where PintMapper reads it; the profile is created active
 * the first time. Its TRN is also the organization's VAT number, so one TRN
 * cannot be claimed by two organizations.
 *
 * The API, the portal and sign-up all save through this, so they cannot
 * disagree.
 */
class UaeSellerDetails
{
    public const JURISDICTION = 'AE';

    public const ENGINE = 'peppol';

    /** The compliance profile settings this writes, each one PintMapper reads. */
    public const SETTINGS = [
        'trn',
        'tin',
        'endpoint_id',
        'emirate',
        'legal_registration_type',
        'legal_registration_id',
        'legal_registration_authority',
        'payment_account',
    ];

    public function __construct(
        private readonly Registrar $registrar,
    ) {}

    /**
     * @param  array<string, mixed>  $details  validated by SellerRules::rules()
     */
    public function save(Organization $organization, array $details): ComplianceProfile
    {
        return DB::transaction(function () use ($organization, $details): ComplianceProfile {
            $trn = self::text($details['trn'] ?? null);

            $this->registrar->amend($organization, new OrganizationChangesData(
                name: self::text($details['name'] ?? null),
                nameAr: self::text($details['name_ar'] ?? null),
                vatNumber: $trn,
                pricesIncludeVat: array_key_exists('prices_include_vat', $details) ? (bool) $details['prices_include_vat'] : null,
                address: AddressFields::from($details),
            ));

            $settings = [];

            foreach (self::SETTINGS as $key) {
                if (array_key_exists($key, $details)) {
                    $settings[$key] = self::text($details[$key]);
                }
            }

            // A VAT-registered seller's TIN is its TRN's first ten digits.
            if ($trn !== null && ($settings['tin'] ?? null) === null) {
                $settings['tin'] = substr($trn, 0, 10);
            }

            $profile = $organization->complianceProfiles()
                ->where('jurisdiction', self::JURISDICTION)
                ->lockForUpdate()
                ->first();

            if ($profile === null) {
                return $organization->complianceProfiles()->create([
                    'jurisdiction' => self::JURISDICTION,
                    'engine' => self::ENGINE,
                    'status' => ComplianceProfile::STATUS_ACTIVE,
                    'settings' => $settings,
                ]);
            }

            $profile->update(['settings' => array_merge($profile->settings ?? [], $settings)]);

            return $profile;
        });
    }

    /**
     * The UAE settings as they are stored, for the company page to show.
     *
     * @return array<string, string|null>
     */
    public function current(Organization $organization): array
    {
        $settings = (array) ($this->profile($organization)?->settings ?? []);

        return array_map(
            fn (string $key): ?string => self::text($settings[$key] ?? null),
            array_combine(self::SETTINGS, self::SETTINGS),
        );
    }

    /**
     * Whether every detail a document needs from the seller is here: the
     * name and address with its emirate, a TRN or TIN, the endpoint the
     * service provider issued and the legal registration.
     */
    public function complete(Organization $organization): bool
    {
        $settings = $this->current($organization);

        return filled($organization->name)
            && filled($organization->street)
            && filled($organization->city)
            && in_array($settings['emirate'], PintAe::EMIRATES, true)
            && ($settings['trn'] !== null || $settings['tin'] !== null)
            && preg_match('/^'.PintAe::SCHEME.':\d{10}$/', (string) $settings['endpoint_id']) === 1
            && $settings['legal_registration_type'] !== null
            && $settings['legal_registration_id'] !== null;
    }

    private function profile(Organization $organization): ?ComplianceProfile
    {
        return $organization->complianceProfiles()
            ->where('jurisdiction', self::JURISDICTION)
            ->first();
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
