{{--
    Sign-up's section for a company registered in the UAE: what the UAE
    rules need to know the seller, and not the Peppol endpoint, which the
    service provider issues after sign-up and the company page takes.
--}}
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
