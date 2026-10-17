<?php

declare(strict_types=1);

namespace App\Domains\Organization\Rules;

use App\Domains\Compliance\Engine\Services\JurisdictionRegistry;
use App\Domains\Compliance\Peppol\Profiles\PintAe;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\Registrar;
use Closure;
use Illuminate\Validation\Rule;

/**
 * What a seller's details have to be before ZATCA accepts an invoice from it.
 *
 * One set of rules for every way an organization's details arrive - the API,
 * the portal's company page and sign-up - so the three cannot disagree. Each
 * shape is the authority's: BR-KSA-40 for the VAT number, BR-KSA-37 for the
 * building number, BR-KSA-66 for the postal code. The address had no way in
 * at all except a sandbox command, so no organization could pass
 * Organization::hasCompleteZatcaProfile() and onboard.
 *
 * The Saudi shapes apply to a Saudi organization. VatNumber alone stays
 * neutral, because a UAE TRN is also fifteen digits. A UAE organization is
 * checked by uae() instead, in PINT AE's shapes.
 */
final class CompanyProfileRules
{
    /**
     * @param  bool  $complete  Every field required, as the company page and
     *                          sign-up ask for them. Otherwise each is
     *                          checked only when sent, as the API allows.
     * @param  Organization|null  $existing  The organization being changed.
     * @return array<string, list<mixed>>
     */
    public static function rules(string $country, bool $complete, ?Organization $existing = null): array
    {
        if ($country === 'AE') {
            return self::uae($complete, $existing);
        }

        $need = $complete ? 'required' : 'sometimes';
        $saudi = $country === 'SA';

        return [
            'name' => [$need, 'string', 'max:255'],
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:255'],
            'vat_number' => [
                $complete ? 'required' : 'nullable',
                'string',
                new VatNumber,
                ...($saudi ? ['regex:/^3\d{13}3$/'] : []),
                self::fixedOnceOnboarded($existing),
                self::unclaimed($existing),
            ],
            'cr_number' => ['sometimes', 'nullable', 'string', 'regex:/^\d{10}$/'],
            'street' => [$need, 'string', 'max:127'],
            'building_number' => [$need, 'string', 'regex:/^\d{4}$/'],
            'additional_street' => ['sometimes', 'nullable', 'string', 'max:127'],
            'district' => [$need, 'string', 'max:127'],
            'city' => [$need, 'string', 'max:127'],
            'postal_code' => [$need, 'string', 'regex:/^\d{5}$/'],
        ];
    }

    /**
     * A UAE seller, in the shapes PINT AE checks.
     *
     * A UAE address has no building number or postal code of a fixed shape,
     * and most have no postal code at all; the emirate is what a document
     * names (IBR-128-AE). The tax identity is the UAE's: a TRN for a
     * VAT-registered seller (IBR-132-AE), otherwise its TIN (IBR-148-AE),
     * the Peppol endpoint the service provider issued, and the legal
     * registration a document carries (IBR-172-AE, IBR-173-AE). Stored as
     * the UAE compliance profile's settings, where PintMapper reads them.
     *
     * @return array<string, list<mixed>>
     */
    private static function uae(bool $complete, ?Organization $existing): array
    {
        $need = $complete ? 'required' : 'sometimes';

        return [
            'name' => [$need, 'string', 'max:255'],
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:255'],
            'street' => [$need, 'string', 'max:127'],
            'building_number' => ['sometimes', 'nullable', 'string', 'max:127'],
            'additional_street' => ['sometimes', 'nullable', 'string', 'max:127'],
            'district' => ['sometimes', 'nullable', 'string', 'max:127'],
            'city' => [$need, 'string', 'max:127'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'emirate' => [$need, 'string', Rule::in(PintAe::EMIRATES)],
            'trn' => ['sometimes', 'nullable', 'string', 'regex:/^1\d{12}03$/', self::unclaimed($existing)],
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

    /**
     * The countries an organization may be registered in on this deployment:
     * the ones the engine issues in, as the admin console's switches or each
     * country's default leave them.
     *
     * @return list<string>
     */
    public static function countries(): array
    {
        return app(JurisdictionRegistry::class)->enabled();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function country(): array
    {
        return ['country' => ['nullable', 'string', Rule::in(self::countries())]];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'vat_number.regex' => __('A Saudi VAT number is 15 digits that start and end with 3.'),
            'cr_number.regex' => __('The commercial registration number is 10 digits.'),
            'building_number.regex' => __('The building number is 4 digits.'),
            'postal_code.regex' => __('The postal code is 5 digits.'),
            'country.in' => __('This deployment registers organizations in: :countries.', ['countries' => implode(', ', self::countries())]),
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

    /**
     * A VAT number is one taxpayer. Held by another organization, it is a
     * duplicate or somebody else's claim, on every way in - not only sign-up.
     */
    private static function unclaimed(?Organization $existing): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($existing): void {
            if (app(Registrar::class)->vatInUse((string) $value, $existing?->id === null ? null : (string) $existing->id)) {
                $fail(__('A company with this VAT number is already registered. Ask its administrator to add you to it.'));
            }
        };
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
