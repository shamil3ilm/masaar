{{--
    Sign-up's section for a company registered in Saudi Arabia, when the
    form offers more than one country: what ZATCA onboarding needs.
--}}
<fieldset class="space-y-4">
    <legend class="mb-2 text-sm font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Your company, as registered with ZATCA') }}</legend>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        @include('auth.partials.saudi-registration')
    </div>
</fieldset>

@include('auth.partials.saudi-address')
