<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\UnitedArabEmirates\Rules;

use App\Domains\Compliance\Countries\UnitedArabEmirates\Support\EinvoicingStart;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Support\ProviderAppointments;
use App\Domains\Compliance\Countries\UnitedArabEmirates\Support\UaeSellerDetails;
use App\Domains\Compliance\Engine\Contracts\SellerRules as SellerRulesPort;
use App\Domains\Compliance\Networks\Peppol\Gateways\AspGatewayResolver;
use App\Domains\Organization\DTOs\AddressFields;
use App\Domains\Organization\DTOs\OrganizationChangesData;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Rules\CompanyProfileRules;
use App\Domains\Organization\Services\Registrar;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * A UAE seller, in the shapes PINT AE checks.
 *
 * A UAE address has no building number or postal code of a fixed shape,
 * and most have no postal code at all; the emirate is what a document
 * names (IBR-128-AE). The tax identity is the UAE's: a TRN for a
 * VAT-registered seller (IBR-132-AE), otherwise its TIN (IBR-148-AE),
 * the Peppol endpoint the service provider issued, and the legal
 * registration a document carries (IBR-172-AE, IBR-173-AE). Stored as the
 * UAE compliance profile's settings, where PintMapper reads them, by
 * UaeSellerDetails.
 */
class SellerRules implements SellerRulesPort
{
    /**
     * What sign-up asks: everything but the Peppol endpoint, which the
     * service provider issues after sign-up and the company page takes, and
     * the optional payment account.
     */
    private const SIGNUP_FIELDS = [
        'trn', 'tin', 'street', 'building_number', 'additional_street', 'district', 'city', 'postal_code', 'emirate',
        'legal_registration_type', 'legal_registration_id', 'legal_registration_authority',
    ];

    public function __construct(
        private readonly UaeSellerDetails $details,
        private readonly Registrar $registrar,
        private readonly ProviderAppointments $appointments,
        private readonly EinvoicingStart $start,
    ) {}

    public function rules(bool $complete, ?Organization $existing = null): array
    {
        $need = $complete ? 'required' : 'sometimes';

        return [
            'street' => [$need, 'string', 'max:127'],
            'building_number' => ['sometimes', 'nullable', 'string', 'max:127'],
            'additional_street' => ['sometimes', 'nullable', 'string', 'max:127'],
            'district' => ['sometimes', 'nullable', 'string', 'max:127'],
            'city' => [$need, 'string', 'max:127'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'emirate' => [$need, 'string', Rule::in(PintAe::EMIRATES)],
            'trn' => ['sometimes', 'nullable', 'string', 'regex:/^1\d{12}03$/', CompanyProfileRules::unclaimed($existing)],
            'tin' => [$complete ? 'required_without:trn' : 'sometimes', 'nullable', 'string', new UaeTin],
            'endpoint_id' => [$need, 'string', 'regex:/^'.PintAe::SCHEME.':\d{10}$/'],
            'legal_registration_type' => [$need, 'string', Rule::in(PintAe::LEGAL_REGISTRATION_TYPES)],
            'legal_registration_id' => [$need, 'string', 'max:100'],
            'legal_registration_authority' => [
                'nullable', 'string', 'max:255',
                'required_if:legal_registration_type,TL,PAS',
                Rule::when(fn ($input): bool => $input->get('legal_registration_type') === 'PAS', ['regex:/^[A-Z]{2}$/']),
            ],
            'payment_account' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'emirate.in' => __('The emirate is one of :codes.', ['codes' => implode(', ', PintAe::EMIRATES)]),
            'trn.regex' => __('A TRN is 15 digits that start with 1 and end with 03.'),
            'tin.required_without' => __('A seller with no TRN gives its TIN.'),
            'endpoint_id.regex' => __('The Peppol endpoint is :scheme: followed by 10 digits, as the service provider issued it.', ['scheme' => PintAe::SCHEME]),
            'legal_registration_type.in' => __('The legal registration type is one of :types.', ['types' => implode(', ', PintAe::LEGAL_REGISTRATION_TYPES)]),
            'legal_registration_authority.required_if' => __('A trade licence names its issuing authority, and a passport its issuing country.'),
            'legal_registration_authority.regex' => __('A passport names its issuing country as a two-letter code.'),
            'payment_account.regex' => __('The payment account is an IBAN.'),
        ];
    }

    public function signupFields(): array
    {
        return self::SIGNUP_FIELDS;
    }

    public function signupView(): string
    {
        return 'countries.united-arab-emirates.signup';
    }

    public function signupNext(): string
    {
        return __('We review every company before it can issue. You can sign in and prepare invoices straight away; issuing opens once your company is approved and you have added the Peppol endpoint your service provider issues you on your company page.');
    }

    public function companyView(): string
    {
        return 'countries.united-arab-emirates.company';
    }

    public function companyData(Organization $organization): array
    {
        return [
            'uae' => $this->details->current($organization),
            // The service provider it appointed, and by when it must (p14, p19).
            'appointment' => $this->appointments->current((string) $organization->id),
            'appointmentDeadline' => $this->appointments->deadline((string) $organization->id),
            'providers' => AspGatewayResolver::configured(),
            // When its e-invoicing starts, beside the published phases as a
            // hint (Guidelines v1.1, 8.2-8.3).
            'einvoicingStart' => $this->start->date($organization),
            'einvoicingPhases' => EinvoicingPhases::MANDATORY,
            'voluntaryFrom' => EinvoicingPhases::VOLUNTARY_FROM,
        ];
    }

    /** The TRN: a seller with only a TIN is not VAT-registered, and has none. */
    public function vatNumber(array $details): ?string
    {
        $trn = $details['trn'] ?? null;

        return is_string($trn) && $trn !== '' ? $trn : null;
    }

    /**
     * With any of the UAE settings, through UaeSellerDetails, which keeps
     * them on the profile; without, as the organization's own columns only,
     * so the API can change a name without creating a profile.
     */
    public function save(Organization $organization, array $details): void
    {
        if (Arr::hasAny($details, UaeSellerDetails::SETTINGS)) {
            $this->details->save($organization, $details);

            return;
        }

        $this->registrar->amend($organization, new OrganizationChangesData(
            name: $details['name'] ?? null,
            nameAr: $details['name_ar'] ?? null,
            pricesIncludeVat: array_key_exists('prices_include_vat', $details) ? (bool) $details['prices_include_vat'] : null,
            address: AddressFields::from($details),
        ));
    }

    /** Only the settings: sign-up's 'name' is the person's, not the company's. */
    public function registered(Organization $organization, array $details): void
    {
        $settings = Arr::only($details, UaeSellerDetails::SETTINGS);

        if ($settings !== []) {
            $this->details->save($organization, $settings);
        }
    }

    public function complete(Organization $organization): bool
    {
        return $this->details->complete($organization);
    }
}
