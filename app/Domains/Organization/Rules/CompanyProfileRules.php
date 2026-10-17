<?php

declare(strict_types=1);

namespace App\Domains\Organization\Rules;

use App\Domains\Compliance\Engine\Contracts\Jurisdiction;
use App\Domains\Compliance\Engine\Services\JurisdictionRegistry;
use App\Domains\Organization\Models\Organization;
use App\Domains\Organization\Services\Registrar;
use Closure;
use Illuminate\Validation\Rule;

/**
 * What a seller's details have to be, on every way they arrive - the API,
 * the portal's company page and sign-up - so the three cannot disagree.
 *
 * This is the part every country shares: the company's names, which
 * countries an organization may be registered in, and that one VAT number is
 * one taxpayer. The rest is the country's own, from its module's
 * SellerRules (docs/architecture/JURISDICTION-ROUTING.md), so nothing here
 * knows which shapes apply where.
 */
final class CompanyProfileRules
{
    /**
     * The module whose seller rules apply to a country: that country's,
     * switched on or not, or the default module's for a blank country or one
     * with no module.
     */
    public static function jurisdiction(?string $country): Jurisdiction
    {
        return app(JurisdictionRegistry::class)->orDefault($country);
    }

    /**
     * @param  bool  $complete  Every field required, as the company page and
     *                          sign-up ask for them. Otherwise each is
     *                          checked only when sent, as the API allows.
     * @param  Organization|null  $existing  The organization being changed.
     * @return array<string, list<mixed>>
     */
    public static function rules(?string $country, bool $complete, ?Organization $existing = null): array
    {
        $need = $complete ? 'required' : 'sometimes';

        return [
            'name' => [$need, 'string', 'max:255'],
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:255'],
            ...self::jurisdiction($country)->sellerRules()->rules($complete, $existing),
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
    public static function messages(?string $country): array
    {
        return [
            ...self::jurisdiction($country)->sellerRules()->messages(),
            'country.in' => __('This deployment registers organizations in: :countries.', ['countries' => implode(', ', self::countries())]),
        ];
    }

    /**
     * A VAT number is one taxpayer. Held by another organization, it is a
     * duplicate or somebody else's claim, on every way in - not only sign-up.
     * Each country's SellerRules puts it on the number it registers under.
     */
    public static function unclaimed(?Organization $existing): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($existing): void {
            if (app(Registrar::class)->vatInUse((string) $value, $existing?->id === null ? null : (string) $existing->id)) {
                $fail(__('A company with this VAT number is already registered. Ask its administrator to add you to it.'));
            }
        };
    }
}
