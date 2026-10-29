<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Countries\SaudiArabia\Rules;

use App\Domains\Compliance\Countries\SaudiArabia\Support\TextNormalizer;
use App\Domains\Compliance\Engine\Contracts\SellerRules as SellerRulesPort;
use App\Domains\Organization\DTOs\AddressFields;
use App\Domains\Organization\DTOs\OrganizationChangesData;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Rules\CompanyProfileRules;
use App\Domains\Organization\Rules\VatNumber;
use App\Domains\Organization\Services\Registrar;
use Closure;

/**
 * What a seller's details have to be before ZATCA accepts an invoice from it.
 *
 * Each shape is the authority's: BR-KSA-40 for the VAT number, BR-KSA-37 for
 * the building number, BR-KSA-66 for the postal code, and the Arabic name
 * from the Detailed Guidelines' language requirement (ArabicText). The address had no way
 * in at all except a sandbox command, so no organization could pass
 * Organization::hasCompleteZatcaProfile() and onboard.
 *
 * Everything is kept on the organization's own columns.
 */
class SellerRules implements SellerRulesPort
{
    /** What sign-up asks beyond the company's names, as ZATCA onboarding needs it. */
    private const SIGNUP_FIELDS = [
        'vat_number', 'cr_number', 'street', 'building_number', 'additional_street', 'district', 'city', 'postal_code',
    ];

    /** The most a QR TLV value holds: its length is one byte. */
    private const QR_TEXT_BYTES = 255;

    public function __construct(
        private readonly Registrar $registrar,
    ) {}

    public function rules(bool $complete, ?Organization $existing = null): array
    {
        $need = $complete ? 'required' : 'sometimes';

        return [
            // The seller's name as the invoice and the QR's tag 1 carry it
            // (Organization::documentName()), in Arabic letters: ZATCA's
            // E-Invoicing Detailed Guidelines (v2, May 2023) p60, invoice
            // data "must be in Arabic" (ArabicText). Required wherever the
            // whole profile is - sign-up and the company page - and, sent
            // through the API, never emptied.
            'name_ar' => [
                ...($complete ? [] : ['sometimes']),
                new ArabicName(__('Arabic seller name required (ZATCA: the invoice must be in Arabic)', [], 'en')),
                'string',
                'max:255',
                // The QR carries the name in TLV tag 1, whose length is one
                // byte: 255 bytes of UTF-8 at most (Security Features
                // Implementation Standard, QR code; TlvEncoder). An Arabic
                // letter is two bytes, so 255 characters can be 510 bytes,
                // and a name that long was accepted here and then failed
                // every signature. Counted as the document writes it
                // (TextNormalizer::legalName).
                function (string $attribute, mixed $value, Closure $fail): void {
                    $bytes = strlen(TextNormalizer::legalName((string) $value));

                    if ($bytes > self::QR_TEXT_BYTES) {
                        $fail(__('The Arabic seller name is :bytes bytes long in the QR code, which holds at most :max. Shorten it.', ['bytes' => $bytes, 'max' => self::QR_TEXT_BYTES]));
                    }
                },
            ],
            'vat_number' => [
                $complete ? 'required' : 'nullable',
                'string',
                new VatNumber,
                'regex:/^3\d{13}3$/',
                self::fixedOnceOnboarded($existing),
                // Every rehearsal and demo company holds the sandbox's test number.
                CompanyProfileRules::unclaimed($existing, [FatooraEnvironment::SANDBOX_VAT_NUMBER]),
            ],
            // The seller identification and its scheme (BR-KSA-08): CRN unless
            // another one ZATCA accepts is chosen, each in its own shape.
            'seller_id_scheme' => ['sometimes', 'nullable', 'string'],
            'cr_number' => ['sometimes', 'nullable', 'string', new SellerIdNumber($existing === null ? SellerIdentification::DEFAULT : SellerIdentification::scheme($existing))],
            'street' => [$need, 'string', 'max:127'],
            'building_number' => [$need, 'string', 'regex:/^\d{4}$/'],
            'additional_street' => ['sometimes', 'nullable', 'string', 'max:127'],
            'district' => [$need, 'string', 'max:127'],
            'city' => [$need, 'string', 'max:127'],
            'postal_code' => [$need, 'string', 'regex:/^\d{5}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'vat_number.regex' => __('A Saudi VAT number is 15 digits that start and end with 3.'),
            'building_number.regex' => __('The building number is 4 digits.'),
            'postal_code.regex' => __('The postal code is 5 digits.'),
        ];
    }

    public function signupFields(): array
    {
        return self::SIGNUP_FIELDS;
    }

    public function signupView(): string
    {
        return 'countries.saudi-arabia.signup.company';
    }

    public function signupNext(): string
    {
        return __('We review every company before it connects to ZATCA. You can sign in and prepare invoices straight away; connecting to ZATCA and issuing open once your company is approved.');
    }

    public function companyView(): string
    {
        return 'countries.saudi-arabia.company';
    }

    public function companyData(Organization $organization): array
    {
        return [
            'onboarded' => (bool) ($organization->compliance_profile['zatca_onboarded'] ?? false),
            'sellerIdScheme' => SellerIdentification::scheme($organization),
            'sellerIdSchemes' => SellerIdentification::choices(),
        ];
    }

    public function vatNumber(array $details): ?string
    {
        return isset($details['vat_number']) ? (string) $details['vat_number'] : null;
    }

    public function save(Organization $organization, array $details): void
    {
        $this->registrar->amend($organization, new OrganizationChangesData(
            name: $details['name'] ?? null,
            nameAr: $details['name_ar'] ?? null,
            vatNumber: $details['vat_number'] ?? null,
            pricesIncludeVat: array_key_exists('prices_include_vat', $details) ? (bool) $details['prices_include_vat'] : null,
            address: AddressFields::from($details),
            profile: filled($details['seller_id_scheme'] ?? null)
                ? [SellerIdentification::PROFILE_KEY => (string) $details['seller_id_scheme']]
                : null,
        ));
    }

    /** Nothing: every detail is one of the organization's own columns. */
    public function registered(Organization $organization, array $details): void {}

    /** None: the return is not split by region. */
    public function branchRegions(): array
    {
        return [];
    }

    public function complete(Organization $organization): bool
    {
        return $organization->hasCompleteZatcaProfile();
    }

    /**
     * The certificate ZATCA issues at onboarding names the VAT number, and
     * every invoice is signed with it. A new number afterwards would put one
     * VAT number on the invoice and another in its signature.
     */
    private static function fixedOnceOnboarded(?Organization $existing): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($existing): void {
            if ($existing === null || ! ($existing->compliance_profile['zatca_onboarded'] ?? false)) {
                return;
            }

            if ((string) $value !== (string) $existing->vat_number) {
                $fail(__('The VAT number cannot change after ZATCA onboarding: the certificate is issued for it.'));
            }
        };
    }
}
