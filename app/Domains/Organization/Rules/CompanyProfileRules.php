<?php

declare(strict_types=1);

namespace App\Domains\Organization\Rules;

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
 * neutral, because a UAE TRN is also fifteen digits.
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
     * The countries an organization may be registered in on this deployment.
     *
     * @return list<string>
     */
    public static function countries(): array
    {
        return config('peppol.countries.AE.enabled') ? ['SA', 'AE'] : ['SA'];
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
