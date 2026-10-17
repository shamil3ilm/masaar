{{--
    Sign-up when this deployment registers companies in more than one
    country: the country first, then only that country's section.

    Both sections are rendered. The one not chosen is hidden and disabled, so
    its fields are neither sent nor required by the browser; the
    signupCountry component (resources/js/signup-country.js) swaps the two
    when the choice changes, and the server renders the same state from
    old('country').

    The UAE section asks for what the UAE rules need to know the seller, and
    not the Peppol endpoint: the service provider issues that after sign-up,
    and the company page takes it then.

    Only the countries this deployment registers are offered: a country an
    admin has switched off on the Countries page has no radio.

    @param string $country  the country chosen, 'SA' or 'AE'
    @param list<string> $countries  the countries offered
--}}
<fieldset class="space-y-4">
    <legend class="mb-2 text-sm font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Where your company is registered') }}</legend>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2" @change="choose">
        @if (in_array('SA', $countries, true))
        <label class="flex min-h-11 cursor-pointer items-center gap-3 rounded border border-gray-300 dark:border-gray-700 px-4 py-3 text-sm font-medium text-gray-800 dark:text-gray-100 has-checked:border-teal-600 has-checked:ring-1 has-checked:ring-teal-600">
            <input type="radio" name="country" value="SA" class="text-teal-600 dark:text-teal-400" @checked($country === 'SA')>
            {{ __('Saudi Arabia') }}
        </label>
        @endif
        @if (in_array('AE', $countries, true))
        <label class="flex min-h-11 cursor-pointer items-center gap-3 rounded border border-gray-300 dark:border-gray-700 px-4 py-3 text-sm font-medium text-gray-800 dark:text-gray-100 has-checked:border-teal-600 has-checked:ring-1 has-checked:ring-teal-600">
            <input type="radio" name="country" value="AE" class="text-teal-600 dark:text-teal-400" @checked($country === 'AE')>
            {{ __('United Arab Emirates') }}
        </label>
        @endif
    </div>
</fieldset>

<fieldset class="space-y-6" data-country-section="SA" :hidden="notSaudi" :disabled="notSaudi" @if ($country !== 'SA') hidden disabled @endif>
    <fieldset class="space-y-4">
        <legend class="mb-2 text-sm font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Your company, as registered with ZATCA') }}</legend>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @include('auth.partials.saudi-registration')
        </div>
    </fieldset>

    @include('auth.partials.saudi-address')
</fieldset>

<fieldset class="space-y-6" data-country-section="AE" :hidden="notUae" :disabled="notUae" @if ($country !== 'AE') hidden disabled @endif>
    <fieldset class="space-y-4">
        <legend class="mb-2 text-sm font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Registration') }}</legend>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @include('portal.partials.uae-tax-identity', ['values' => []])
            @include('portal.partials.uae-legal-registration', ['values' => []])
        </div>
    </fieldset>

    <fieldset class="space-y-4">
        <legend class="mb-2 text-sm font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Address') }}</legend>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @include('portal.partials.uae-address', ['values' => []])
        </div>
    </fieldset>
</fieldset>
