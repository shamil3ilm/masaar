{{--
    The company page for a UAE seller.

    What PINT AE needs from the seller, in its shapes: the address names its
    emirate and has no fixed building number or postal code (IBR-128-AE);
    the tax identity is a TRN for a VAT-registered seller, otherwise a TIN
    (IBR-132-AE, IBR-148-AE); the Peppol endpoint is the one the service
    provider issued; and the legal registration goes on every document
    (IBR-172-AE, IBR-173-AE). Validated and saved by the UAE module's
    SellerRules (through UaeSellerDetails), as the API does.

    @param \App\Domains\Organization\Models\Organization $organization
    @param array<string, string|null>                    $uae  the stored UAE settings
    @param ProviderAppointment|null                       $appointment  the one in force (SellerRules::companyData)
    @param \Carbon\CarbonImmutable|null                   $appointmentDeadline
    @param array<string, string>                          $providers  purpose key => label
--}}
<x-ui.page-header :title="__('Company')" :description="__('The seller details on every UAE e-invoice you issue, in the shapes the UAE e-invoicing rules check.')" />

{{-- The form's errors are listed by the layout's x-ui.flash. --}}

<form method="POST" action="{{ route('portal.company.update') }}" class="mb-6 max-w-2xl">
    @csrf

    <div class="mb-6 rounded-card bg-surface border border-line shadow-card">
        <div class="border-b p-4">
            <h3 class="font-semibold text-fg">{{ __('Registration') }}</h3>
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
                <span class="mt-1 block text-xs font-normal text-muted">{{ __('As your e-invoicing service provider issued it: 0235: followed by 10 digits. Invoices sent to you arrive here.') }}</span>
            </x-ui.field>

            @include('portal.partials.uae-legal-registration', ['values' => $uae])

            <x-ui.field :label="__('Payment account (IBAN)')">
                <x-ui.input type="text" name="payment_account" maxlength="34" dir="ltr"
                            value="{{ old('payment_account', $uae['payment_account']) }}" />
                <span class="mt-1 block text-xs font-normal text-muted">{{ __('Optional. Printed on invoices paid by bank transfer.') }}</span>
            </x-ui.field>
        </div>
    </div>

    <div class="mb-6 rounded-card bg-surface border border-line shadow-card">
        <div class="border-b p-4">
            <h3 class="font-semibold text-fg">{{ __('Address') }}</h3>
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

{{-- The service provider this organization appointed: one, for sending and
     receiving, through EmaraTax, by the last date its revenue band sets (UAE
     Electronic Invoicing Guidelines v1.1, p14, p19, p38-39). Saved by
     PortalProviderAppointmentController; recording one also routes the
     organization's e-invoices through it (AspGatewayResolver). --}}
<form method="POST" action="{{ route('portal.company.service-provider') }}" class="mb-6 max-w-2xl" data-service-provider
      data-confirm="{{ __('Save your e-invoicing service provider?') }}"
      data-confirm-detail="{{ __('Your e-invoices are sent and received through the provider you record here.') }}"
      data-confirm-action="{{ __('Save service provider') }}">
    @csrf
    <x-ui.section :title="__('E-invoicing service provider')">
        <div class="space-y-4">
            <p class="text-sm text-muted">
                @if ($appointmentDeadline)
                    {{ __('Appoint one accredited service provider for sending and receiving e-invoices through EmaraTax by :date, then record it here.', ['date' => $appointmentDeadline->toDateString()]) }}
                @else
                    {{ __('Appoint one accredited service provider for sending and receiving e-invoices through EmaraTax, then record it here.') }}
                @endif
            </p>

            <x-ui.field :label="__('Service provider')" error="provider">
                <x-ui.select name="provider" required>
                    @foreach ($providers as $key => $label)
                        <option value="{{ $key }}" @selected(old('provider', $appointment?->provider ?? 'platform') === $key)>{{ __($label) }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>

            @php($appointmentStatus = old('status', $appointment?->status ?? 'pending'))
            <x-ui.field :label="__('Appointment')" error="status">
                <x-ui.select name="status" required>
                    <option value="pending" @selected($appointmentStatus === 'pending')>{{ __('Not yet appointed') }}</option>
                    <option value="appointed" @selected($appointmentStatus === 'appointed')>{{ __('Appointed') }}</option>
                </x-ui.select>
            </x-ui.field>

            <x-ui.field :label="__('Appointed on')" error="appointed_at">
                <x-ui.date-input name="appointed_at" value="{{ old('appointed_at', $appointment?->appointed_at?->toDateString()) }}" />
            </x-ui.field>

            <x-ui.field :label="__('Appointment reference')" error="reference">
                <x-ui.input type="text" name="reference" maxlength="255" dir="ltr" value="{{ old('reference', $appointment?->reference) }}" />
                <span class="mt-1 block text-xs font-normal text-muted">{{ __('Optional. The reference EmaraTax or the provider gave for the appointment.') }}</span>
            </x-ui.field>

            <x-ui.field :label="__('Your last date to appoint')" error="deadline_on">
                <x-ui.date-input name="deadline_on" value="{{ old('deadline_on', $appointment?->deadline_on?->toDateString()) }}" />
                <span class="mt-1 block text-xs font-normal text-muted">{{ __('Optional. Only if your revenue band sets a different date from the one shown above.') }}</span>
            </x-ui.field>

            <x-ui.button variant="secondary">{{ __('Save service provider') }}</x-ui.button>
        </div>
    </x-ui.section>
</form>
