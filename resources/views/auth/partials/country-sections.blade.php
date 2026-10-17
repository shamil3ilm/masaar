{{--
    Sign-up when this deployment registers companies in more than one
    country: the country first, then only that country's section.

    Every section is rendered. The ones not chosen are hidden and disabled,
    so their fields are neither sent nor required by the browser; the
    signupCountry component (resources/js/signup-country.js) swaps them when
    the choice changes, and the server renders the same state from
    old('country').

    Each section is its country module's (SellerRules::signupView()), and
    asks for what that country's rules need to know the seller.

    Only the countries this deployment registers are offered: a country an
    admin has switched off on the Countries page has no radio.

    @param string $country  the country chosen
    @param list<array{code: string, name: string, view: string}> $sections  the countries offered, the default first
--}}
<fieldset class="space-y-4">
    <legend class="mb-2 text-sm font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Where your company is registered') }}</legend>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2" @change="choose">
        @foreach ($sections as $section)
        <label class="flex min-h-11 cursor-pointer items-center gap-3 rounded border border-gray-300 dark:border-gray-700 px-4 py-3 text-sm font-medium text-gray-800 dark:text-gray-100 has-checked:border-teal-600 has-checked:ring-1 has-checked:ring-teal-600">
            <input type="radio" name="country" value="{{ $section['code'] }}" class="text-teal-600 dark:text-teal-400" @checked($country === $section['code'])>
            {{ __($section['name']) }}
        </label>
        @endforeach
    </div>
</fieldset>

@foreach ($sections as $section)
<fieldset class="space-y-6" data-country-section="{{ $section['code'] }}" :hidden="country !== '{{ $section['code'] }}'" :disabled="country !== '{{ $section['code'] }}'" @if ($country !== $section['code']) hidden disabled @endif>
    @include($section['view'])
</fieldset>
@endforeach
