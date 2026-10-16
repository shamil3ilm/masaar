{{--
    The company page for a UAE seller.

    What PINT AE needs from the seller, in its shapes: the address names its
    emirate and has no fixed building number or postal code (IBR-128-AE);
    the tax identity is a TRN for a VAT-registered seller, otherwise a TIN
    (IBR-132-AE, IBR-148-AE); the Peppol endpoint is the one the service
    provider issued; and the legal registration goes on every document
    (IBR-172-AE, IBR-173-AE). Validated by CompanyProfileRules for 'AE' and
    saved by UaeSellerDetails, as the API does.

    @param \App\Domains\Organization\Models\Organization $organization
    @param array<string, string|null>                    $uae  the stored UAE settings
--}}
<div class="mb-6">
    <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ __('Company') }}</h2>
    <p class="text-gray-500 dark:text-gray-400">
        {{ __('The seller details on every UAE e-invoice you issue, in the shapes the UAE e-invoicing rules check.') }}
    </p>
</div>

@if ($errors->any())
    <div class="mb-6 rounded border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-950/40 px-4 py-3 text-sm text-red-700 dark:text-red-300">
        <ul class="list-disc ps-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ route('portal.company.update') }}" class="mb-6 max-w-2xl">
    @csrf

    <div class="mb-6 rounded-lg bg-white dark:bg-gray-900 shadow">
        <div class="border-b p-4">
            <h3 class="font-semibold text-gray-800 dark:text-gray-100">{{ __('Registration') }}</h3>
        </div>
        <div class="space-y-4 p-4">
            <x-ui.field :label="__('Legal name')">
                <x-ui.input type="text" name="name" maxlength="255" required
                            value="{{ old('name', $organization->name) }}" />
            </x-ui.field>

            <x-ui.field :label="__('Legal name in Arabic')">
                <x-ui.input type="text" name="name_ar" maxlength="255" dir="rtl"
                            value="{{ old('name_ar', $organization->name_ar) }}" />
            </x-ui.field>

            @include('portal.partials.uae-tax-identity', ['values' => $uae])

            <x-ui.field :label="__('Peppol endpoint')">
                <x-ui.input type="text" name="endpoint_id" maxlength="15" required dir="ltr" placeholder="0235:1234567890"
                            value="{{ old('endpoint_id', $uae['endpoint_id']) }}" />
                <span class="mt-1 block text-xs font-normal text-gray-500 dark:text-gray-400">{{ __('As your e-invoicing service provider issued it: 0235: followed by 10 digits. Invoices sent to you arrive here.') }}</span>
            </x-ui.field>

            @include('portal.partials.uae-legal-registration', ['values' => $uae])

            <x-ui.field :label="__('Payment account (IBAN)')">
                <x-ui.input type="text" name="payment_account" maxlength="34" dir="ltr"
                            value="{{ old('payment_account', $uae['payment_account']) }}" />
                <span class="mt-1 block text-xs font-normal text-gray-500 dark:text-gray-400">{{ __('Optional. Printed on invoices paid by bank transfer.') }}</span>
            </x-ui.field>
        </div>
    </div>

    <div class="mb-6 rounded-lg bg-white dark:bg-gray-900 shadow">
        <div class="border-b p-4">
            <h3 class="font-semibold text-gray-800 dark:text-gray-100">{{ __('Address') }}</h3>
        </div>
        <div class="grid grid-cols-1 gap-4 p-4 sm:grid-cols-2">
            @include('portal.partials.uae-address', ['values' => [
                ...$uae,
                ...$organization->only(['street', 'building_number', 'additional_street', 'district', 'city', 'postal_code']),
            ]])
        </div>
    </div>

    <x-ui.button variant="primary">{{ __('Save company details') }}</x-ui.button>
</form>
