{{--
    Sign-up's country-specific details, when this deployment registers
    companies in more than one country: only the chosen country's section.

    Every section is rendered. The ones not chosen are hidden and disabled,
    so their fields are neither sent nor required by the browser; the
    signupCountry component (resources/js/signup-country.js) swaps them when
    the choice in auth.partials.country-choice changes, and the server
    renders the same state from old('country').

    Each section is its country module's (SellerRules::signupView()), and
    asks for what that country's rules need to know the seller.

    @param string $country  the country chosen
    @param list<array{code: string, name: string, system: string, view: string, next: string}> $sections  the countries offered, the default first
--}}
@foreach ($sections as $section)
<fieldset class="space-y-6" data-country-section="{{ $section['code'] }}" :hidden="country !== '{{ $section['code'] }}'" :disabled="country !== '{{ $section['code'] }}'" @if ($country !== $section['code']) hidden disabled @endif>
    @include($section['view'])
</fieldset>
@endforeach
