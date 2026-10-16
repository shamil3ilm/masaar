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

            <x-ui.field :label="__('TRN')">
                <x-ui.input type="text" name="trn" inputmode="numeric" maxlength="15" dir="ltr"
                            value="{{ old('trn', $uae['trn']) }}" />
                <span class="mt-1 block text-xs font-normal text-gray-500 dark:text-gray-400">{{ __('15 digits, starting with 1 and ending with 03. Leave empty if you are not registered for VAT.') }}</span>
            </x-ui.field>

            <x-ui.field :label="__('TIN')">
                <x-ui.input type="text" name="tin" inputmode="numeric" maxlength="10" dir="ltr"
                            value="{{ old('tin', $uae['tin']) }}" />
                <span class="mt-1 block text-xs font-normal text-gray-500 dark:text-gray-400">{{ __('10 digits, starting with 1. With a TRN it is the TRN\'s first 10 digits and is filled in for you.') }}</span>
            </x-ui.field>

            <x-ui.field :label="__('Peppol endpoint')">
                <x-ui.input type="text" name="endpoint_id" maxlength="15" required dir="ltr" placeholder="0235:1234567890"
                            value="{{ old('endpoint_id', $uae['endpoint_id']) }}" />
                <span class="mt-1 block text-xs font-normal text-gray-500 dark:text-gray-400">{{ __('As your e-invoicing service provider issued it: 0235: followed by 10 digits. Invoices sent to you arrive here.') }}</span>
            </x-ui.field>

            <x-ui.field :label="__('Legal registration type')">
                <x-ui.select name="legal_registration_type" required>
                    <option value="">{{ __('Choose...') }}</option>
                    @include('portal.partials.legal-registration-options', ['selected' => old('legal_registration_type', $uae['legal_registration_type'])])
                </x-ui.select>
            </x-ui.field>

            <x-ui.field :label="__('Legal registration number')">
                <x-ui.input type="text" name="legal_registration_id" maxlength="100" required dir="ltr"
                            value="{{ old('legal_registration_id', $uae['legal_registration_id']) }}" />
            </x-ui.field>

            <x-ui.field :label="__('Issuing authority')">
                <x-ui.input type="text" name="legal_registration_authority" maxlength="255"
                            value="{{ old('legal_registration_authority', $uae['legal_registration_authority']) }}" />
                <span class="mt-1 block text-xs font-normal text-gray-500 dark:text-gray-400">{{ __('Required for a trade licence. For a passport, the two-letter code of the issuing country.') }}</span>
            </x-ui.field>

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
            <x-ui.field :label="__('Street')">
                <x-ui.input type="text" name="street" maxlength="127" required
                            value="{{ old('street', $organization->street) }}" />
            </x-ui.field>

            <x-ui.field :label="__('Building or unit')">
                <x-ui.input type="text" name="building_number" maxlength="127"
                            value="{{ old('building_number', $organization->building_number) }}" />
            </x-ui.field>

            <x-ui.field :label="__('Additional street')">
                <x-ui.input type="text" name="additional_street" maxlength="127"
                            value="{{ old('additional_street', $organization->additional_street) }}" />
            </x-ui.field>

            <x-ui.field :label="__('Area')">
                <x-ui.input type="text" name="district" maxlength="127"
                            value="{{ old('district', $organization->district) }}" />
            </x-ui.field>

            <x-ui.field :label="__('City')">
                <x-ui.input type="text" name="city" maxlength="127" required
                            value="{{ old('city', $organization->city) }}" />
            </x-ui.field>

            <x-ui.field :label="__('Emirate')">
                <x-ui.select name="emirate" required>
                    <option value="">{{ __('Choose...') }}</option>
                    @include('portal.partials.emirate-options', ['selected' => old('emirate', $uae['emirate'])])
                </x-ui.select>
            </x-ui.field>

            <x-ui.field :label="__('P.O. box or postal code')">
                <x-ui.input type="text" name="postal_code" maxlength="20"
                            value="{{ old('postal_code', $organization->postal_code) }}" />
                <span class="mt-1 block text-xs font-normal text-gray-500 dark:text-gray-400">{{ __('Optional.') }}</span>
            </x-ui.field>
        </div>
    </div>

    <x-ui.button variant="primary">{{ __('Save company details') }}</x-ui.button>
</form>
