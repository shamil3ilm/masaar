{{--
    Sign-up's section for a company registered in the UAE: what the UAE
    rules need to know the seller, and not the Peppol endpoint, which the
    service provider issues after sign-up and the company page takes.
--}}
<fieldset class="space-y-4">
    <legend class="text-base font-semibold text-fg">{{ __('Registration') }}</legend>
    <p class="-mt-2 text-sm text-muted">{{ __('Your tax registration and the licence your company trades under, as the authority holds them. They are carried on every e-invoice you send.') }}</p>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        @include('countries.united-arab-emirates.tax-identity', ['values' => []])
        @include('countries.united-arab-emirates.legal-registration', ['values' => []])
    </div>
</fieldset>

<fieldset class="space-y-4">
    <legend class="text-base font-semibold text-fg">{{ __('Address') }}</legend>
    <p class="-mt-2 text-sm text-muted">{{ __('Where your company is registered, including its emirate.') }}</p>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        @include('countries.united-arab-emirates.address', ['values' => []])
    </div>
</fieldset>
