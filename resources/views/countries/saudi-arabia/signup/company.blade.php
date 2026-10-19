{{--
    Sign-up's section for a company registered in Saudi Arabia, when the
    form offers more than one country: what ZATCA onboarding needs.
--}}
<fieldset class="space-y-4">
    <legend class="text-base font-semibold text-fg">{{ __('Your company, as registered with ZATCA') }}</legend>
    <p class="-mt-2 text-sm text-muted">{{ __('ZATCA identifies your company by its VAT number. It is checked on every invoice you issue.') }}</p>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        @include('countries.saudi-arabia.signup.registration')
    </div>
</fieldset>

@include('countries.saudi-arabia.signup.address')
