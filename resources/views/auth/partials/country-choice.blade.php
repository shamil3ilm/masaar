{{--
    Where the company is registered, when this deployment registers companies
    in more than one country. Each option names the system that country's
    invoices are filed through, so the choice says what it decides.

    The signupCountry component (resources/js/signup-country.js) listens for
    the change and shows only that country's section in the next step.

    Only the countries this deployment registers are offered: a country an
    admin has switched off on the Countries page has no radio.

    @param string $country  the country chosen
    @param list<array{code: string, name: string, system: string, view: string}> $sections
--}}
<fieldset>
    <legend class="text-sm font-medium text-fg">{{ __('Where your company is registered') }}</legend>
    <p class="mt-0.5 text-xs text-subtle">{{ __('This decides which authority your invoices go to, and what the next step asks for.') }}</p>

    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2" @change="choose">
        @foreach ($sections as $section)
            <x-ui.radio tile name="country" value="{{ $section['code'] }}" :checked="$country === $section['code']"
                        :description="__('Invoices go through :system.', ['system' => $section['system']])">
                {{ __($section['name']) }}
            </x-ui.radio>
        @endforeach
    </div>
</fieldset>
