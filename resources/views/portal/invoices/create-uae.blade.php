@extends('layouts.portal')

@section('title', __('New invoice - :organization', ['organization' => $organization->name ?? __('Portal')]))

{{--
    A UAE invoice, as PINT AE has it.

    Its own form because the documents differ, not only the fields: an
    invoice or a credit note and nothing else (an increase is a new invoice),
    never simplified (every e-invoice names its buyer), a tax, commercial or
    self-billed category, and the eight-flag transaction type. Validated by
    the UAE module's InvoiceRules (PortalUaeInvoiceRequest) and drafted by
    UaeDrafts, as the API's and the pipeline's invoices are.

    The line rows, the pickers and the add-a-line script are the Saudi form's.
--}}
@section('content')
@php
    // One empty row to start, as on the Saudi form.
    $rows = old('lines', [[]]);
    $flags = array_map('strval', (array) old('flags', []));
    $choice = old('buyer_endpoint_choice', 'own');
    $rates = ['S' => \App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\PintAe::STANDARD_RATE, 'Z' => '0', 'E' => '', 'O' => '', 'AE' => \App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\PintAe::STANDARD_RATE, 'N' => \App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\PintAe::STANDARD_RATE];
    // The positions of the eight-character transaction-type code (BTAE-02), in its order.
    $flagLabels = [
        \App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\PintAe::FLAG_FREE_ZONE => __('Free zone'),
        \App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\PintAe::FLAG_DEEMED_SUPPLY => __('Deemed supply'),
        \App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\PintAe::FLAG_MARGIN_SCHEME => __('Margin scheme'),
        \App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\PintAe::FLAG_SUMMARY => __('Summary invoice'),
        \App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\PintAe::FLAG_CONTINUOUS => __('Continuous supply'),
        \App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\PintAe::FLAG_DISCLOSED_AGENT => __('Disclosed agent billing'),
        \App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\PintAe::FLAG_ECOMMERCE => __('E-commerce supply'),
        \App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\PintAe::FLAG_EXPORT => __('Export'),
    ];
@endphp

<div class="mb-6">
    <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ __('New invoice') }}</h2>
    <p class="text-gray-500 dark:text-gray-400">
        {{ __('Saved as a draft. When it is issued it takes its number and is sent to the buyer as a UAE e-invoice; a draft can be changed.') }}
    </p>
</div>

@if ($errors->any())
    <div class="mb-6 rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-950/40 p-4">
        <p class="text-sm font-semibold text-red-800 dark:text-red-200">
            {{ $errors->count() === 1 ? __('1 problem to fix before this can be saved') : __(':count problems to fix before this can be saved', ['count' => $errors->count()]) }}
        </p>
        <ul class="mt-2 list-disc space-y-1 ps-5 text-sm text-red-700 dark:text-red-300">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ route('portal.invoices.uae.store') }}" data-invoice-form data-uae-invoice-form
      data-default-rates="{{ json_encode($rates) }}">
    @csrf

    <div class="mb-6 rounded-lg bg-white dark:bg-gray-900 p-6 shadow">
        <h3 class="mb-4 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Document') }}</h3>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <label for="invoice_number" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Invoice number') }}</label>
                <x-ui.input type="text" name="invoice_number" id="invoice_number" value="{{ old('invoice_number') }}" placeholder="{{ __('Numbered when issued') }}" />
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Leave empty to take the next number from your numbering when it is issued.') }}</p>
            </div>

            <div>
                <label for="document_type" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Document') }}</label>
                <x-ui.select name="document_type" id="document_type">
                    <option value="invoice" @selected(old('document_type', 'invoice') === 'invoice')>{{ __('Invoice') }}</option>
                    <option value="credit_note" @selected(old('document_type') === 'credit_note')>{{ __('Credit note') }}</option>
                </x-ui.select>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('There is no debit note in the UAE: an increase is a new invoice.') }}</p>
            </div>

            <div>
                <label for="document_category" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Category') }}</label>
                <x-ui.select name="document_category" id="document_category">
                    <option value="tax" @selected(old('document_category', 'tax') === 'tax')>{{ __('Tax invoice') }}</option>
                    <option value="commercial" @selected(old('document_category') === 'commercial')>{{ __('Commercial (no VAT charged)') }}</option>
                    <option value="self_billing" @selected(old('document_category') === 'self_billing')>{{ __('Self-billing') }}</option>
                </x-ui.select>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Commercial: exempt, out-of-scope or zero-rated supplies, or a seller not registered for VAT. Self-billing: you issue it as the buyer, for your supplier.') }}</p>
            </div>

            <div>
                <label for="issue_date" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Issue date') }}</label>
                <x-ui.input type="date" name="issue_date" id="issue_date"
                            value="{{ old('issue_date', now()->toDateString()) }}" required />
            </div>

            <div>
                <label for="supply_date" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Supply date') }}</label>
                <x-ui.input type="date" name="supply_date" id="supply_date" value="{{ old('supply_date') }}" />
            </div>

            <div>
                <label for="supply_end_date" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Supply end date') }}</label>
                <x-ui.input type="date" name="supply_end_date" id="supply_end_date" value="{{ old('supply_end_date') }}" />
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('For a summary invoice or a continuous supply: the end of the period.') }}</p>
            </div>

            <div>
                <label for="due_date" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Due date') }}</label>
                <x-ui.input type="date" name="due_date" id="due_date" value="{{ old('due_date') }}" />
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Required on an invoice, except a deemed supply. Not on a credit note.') }}</p>
            </div>

            <div>
                <label for="currency" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Currency') }}</label>
                <x-ui.input type="text" name="currency" id="currency" maxlength="3" value="{{ old('currency', 'AED') }}" class="uppercase" />
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Anything but AED needs an exchange rate to AED.') }}</p>
            </div>

            <div>
                <label for="exchange_rate" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Exchange rate to AED') }}</label>
                <x-ui.input type="number" step="0.000001" min="0" name="exchange_rate" id="exchange_rate"
                       value="{{ old('exchange_rate') }}" />
            </div>

            <div>
                <label for="payment_means_code" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Payment means') }}</label>
                <x-ui.select name="payment_means_code" id="payment_means_code">
                    <option value="10" @selected(old('payment_means_code', '10') === '10')>{{ __('Cash') }}</option>
                    <option value="30" @selected(old('payment_means_code') === '30')>{{ __('Credit transfer') }}</option>
                    <option value="42" @selected(old('payment_means_code') === '42')>{{ __('Payment to bank account') }}</option>
                    <option value="48" @selected(old('payment_means_code') === '48')>{{ __('Bank card') }}</option>
                    <option value="49" @selected(old('payment_means_code') === '49')>{{ __('Direct debit') }}</option>
                </x-ui.select>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('A transfer prints the IBAN from your company details.') }}</p>
            </div>

            @include('portal.invoices.partials.references')
        </div>
    </div>

    <div class="mb-6 rounded-lg bg-white dark:bg-gray-900 p-6 shadow">
        <h3 class="mb-4 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Buyer') }}</h3>

        @if ($customers->isNotEmpty())
            {{-- Fills the fields below; it submits nothing itself. --}}
            <div class="mb-4 max-w-md">
                <label for="customer_picker" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Saved customer') }}</label>
                <x-ui.select id="customer_picker" data-customer-picker>
                    <option value="">{{ __('Choose to fill in the buyer...') }}</option>
                    @foreach ($customers as $customer)
                        <option value="{{ $customer->id }}" data-fields="{{ json_encode($customer->uaeBuyerFields()) }}">
                            {{ $customer->name }}{{ $customer->vat_number ? ' - '.$customer->vat_number : '' }}
                        </option>
                    @endforeach
                </x-ui.select>
            </div>
        @endif

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <label for="buyer_name" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Name') }}</label>
                <x-ui.input type="text" name="buyer_name" id="buyer_name" value="{{ old('buyer_name') }}" required />
            </div>

            <div>
                <label for="buyer_name_ar" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Name in Arabic') }}</label>
                <x-ui.input type="text" name="buyer_name_ar" id="buyer_name_ar" value="{{ old('buyer_name_ar') }}" dir="rtl" lang="ar" />
            </div>

            <div>
                <label for="buyer_vat_number" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('TRN') }}</label>
                <x-ui.input type="text" name="buyer_vat_number" id="buyer_vat_number" inputmode="numeric" maxlength="15" dir="ltr" value="{{ old('buyer_vat_number') }}" />
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('A buyer registered for VAT in the UAE: 15 digits, starting with 1 and ending with 03.') }}</p>
            </div>

            <div>
                <label for="buyer_endpoint_choice" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Buyer e-invoicing address') }}</label>
                <x-ui.select name="buyer_endpoint_choice" id="buyer_endpoint_choice">
                    <option value="own" @selected($choice === 'own')>{{ __('The buyer\'s own Peppol endpoint') }}</option>
                    <option value="not_yet" @selected($choice === 'not_yet')>{{ __('Buyer not yet on e-invoicing (0235:9900000098)') }}</option>
                    <option value="deemed" @selected($choice === 'deemed')>{{ __('Deemed supply (0235:9900000097)') }}</option>
                    <option value="export" @selected($choice === 'export')>{{ __('Export, buyer outside the UAE (0235:9900000099)') }}</option>
                </x-ui.select>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('The three reserved addresses are for a buyer with no Peppol endpoint of its own. A deemed supply or an export is also marked as such below.') }}</p>
            </div>

            <div>
                <label for="buyer_endpoint" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Buyer Peppol endpoint') }}</label>
                <x-ui.input type="text" name="buyer_endpoint" id="buyer_endpoint" maxlength="60" dir="ltr" placeholder="0235:1234567890" value="{{ old('buyer_endpoint') }}" />
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Used when the buyer has its own endpoint.') }}</p>
            </div>

            <div>
                <label for="buyer_address_street" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Street') }}</label>
                <x-ui.input type="text" name="buyer_address[street]" id="buyer_address_street" value="{{ old('buyer_address.street') }}" required />
            </div>

            <div>
                <label for="buyer_address_building_number" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Building or unit') }}</label>
                <x-ui.input type="text" name="buyer_address[building_number]" id="buyer_address_building_number" value="{{ old('buyer_address.building_number') }}" />
            </div>

            <div>
                <label for="buyer_address_city" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('City') }}</label>
                <x-ui.input type="text" name="buyer_address[city]" id="buyer_address_city" value="{{ old('buyer_address.city') }}" required />
            </div>

            <div>
                <label for="buyer_subdivision" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Emirate or region') }}</label>
                <x-ui.input type="text" name="buyer_subdivision" id="buyer_subdivision" maxlength="50" list="emirate_codes" dir="ltr" value="{{ old('buyer_subdivision') }}" required />
                <datalist id="emirate_codes">
                    @include('portal.partials.emirate-options', ['selected' => null])
                </datalist>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('In the UAE, the emirate code: AUH, DXB, SHJ, AJM, UAQ, RAK or FUJ. Abroad, the buyer\'s region.') }}</p>
            </div>

            <div>
                <label for="buyer_address_postal_code" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('P.O. box or postal code') }}</label>
                <x-ui.input type="text" name="buyer_address[postal_code]" id="buyer_address_postal_code" value="{{ old('buyer_address.postal_code') }}" />
            </div>

            <div>
                <label for="buyer_address_country_code" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Country') }}</label>
                <x-ui.input type="text" name="buyer_address[country_code]" id="buyer_address_country_code" maxlength="2"
                       value="{{ old('buyer_address.country_code', 'AE') }}" class="uppercase" />
            </div>
        </div>

        {{-- Required on a commercial document (it carries no TRN to name the buyer by). --}}
        <h4 class="mt-6 mb-3 text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Buyer legal registration') }}</h4>
        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label for="buyer_legal_registration_type" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Type') }}</label>
                <x-ui.select name="buyer_legal_registration[type]" id="buyer_legal_registration_type">
                    <option value="">{{ __('None') }}</option>
                    @include('portal.partials.legal-registration-options', ['selected' => old('buyer_legal_registration.type')])
                </x-ui.select>
            </div>
            <div>
                <label for="buyer_legal_registration_id" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Number') }}</label>
                <x-ui.input type="text" name="buyer_legal_registration[id]" id="buyer_legal_registration_id" maxlength="100" dir="ltr" value="{{ old('buyer_legal_registration.id') }}" />
            </div>
            <div>
                <label for="buyer_legal_registration_authority" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Issuing authority') }}</label>
                <x-ui.input type="text" name="buyer_legal_registration[authority]" id="buyer_legal_registration_authority" maxlength="255" value="{{ old('buyer_legal_registration.authority') }}" />
            </div>
        </div>
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Required on a commercial document.') }}</p>

        <label class="mt-4 flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
            <input type="checkbox" name="save_customer" value="1" @checked(old('save_customer'))>
            {{ __('Save this buyer to Customers') }}
        </label>
    </div>

    <div class="mb-6 rounded-lg bg-white dark:bg-gray-900 p-6 shadow">
        <h3 class="mb-1 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Transaction type') }}</h3>
        <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">{{ __('Tick each that applies. Most invoices need none.') }}</p>

        {{-- Positions in the eight-character transaction-type code
             (BTAE-02), in the code's order. --}}
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($flagLabels as $position => $label)
                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                    <input type="checkbox" name="flags[]" value="{{ $position }}" @checked(in_array((string) $position, $flags, true))>
                    {{ $label }}
                </label>
            @endforeach
        </div>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <div>
                <label for="beneficiary_id" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Beneficiary ID (free zone)') }}</label>
                <x-ui.input type="text" name="beneficiary_id" id="beneficiary_id" maxlength="50" dir="ltr" value="{{ old('beneficiary_id') }}" />
            </div>
            <div>
                <label for="principal_id" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Principal TRN (disclosed agent billing)') }}</label>
                <x-ui.input type="text" name="principal_id" id="principal_id" inputmode="numeric" maxlength="15" dir="ltr" value="{{ old('principal_id') }}" />
            </div>
        </div>

        {{-- An export or an e-commerce supply states where the goods go;
             left empty, the buyer's address is used. --}}
        <h4 class="mt-6 mb-3 text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Delivery address (optional)') }}</h4>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <div>
                <label for="delivery_address_street" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Street') }}</label>
                <x-ui.input type="text" name="delivery_address[street]" id="delivery_address_street" value="{{ old('delivery_address.street') }}" />
            </div>
            <div>
                <label for="delivery_address_city" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('City') }}</label>
                <x-ui.input type="text" name="delivery_address[city]" id="delivery_address_city" value="{{ old('delivery_address.city') }}" />
            </div>
            <div>
                <label for="delivery_address_subdivision" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Emirate or region') }}</label>
                <x-ui.input type="text" name="delivery_address[subdivision]" id="delivery_address_subdivision" maxlength="50" value="{{ old('delivery_address.subdivision') }}" />
            </div>
            <div>
                <label for="delivery_address_postal_code" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('P.O. box or postal code') }}</label>
                <x-ui.input type="text" name="delivery_address[postal_code]" id="delivery_address_postal_code" value="{{ old('delivery_address.postal_code') }}" />
            </div>
            <div>
                <label for="delivery_address_country_code" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Country') }}</label>
                <x-ui.input type="text" name="delivery_address[country_code]" id="delivery_address_country_code" maxlength="2" class="uppercase" value="{{ old('delivery_address.country_code') }}" />
            </div>
        </div>
    </div>

    <div class="mb-6 rounded-lg bg-white dark:bg-gray-900 p-6 shadow">
        <div class="mb-4 flex items-baseline justify-between">
            <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Lines') }}</h3>
            <button type="button" data-add-line
                    class="rounded border border-teal-700 px-3 py-1 text-sm font-medium text-teal-700 dark:text-teal-300 transition-colors hover:bg-teal-50 dark:hover:bg-teal-950/40">
                {{ __('Add a line') }}
            </button>
        </div>

        <div data-line-rows class="space-y-4">
            @foreach ($rows as $index => $row)
                @include('portal.invoices.partials.uae-line-row', ['index' => $index, 'row' => $row])
            @endforeach
        </div>

        {{-- Cloned by resources/js/invoice-lines.js. --}}
        <template data-line-template>
            @include('portal.invoices.partials.uae-line-row', ['index' => 0, 'row' => []])
        </template>
    </div>

    <div class="mb-6 rounded-lg bg-white dark:bg-gray-900 p-6 shadow">
        <h3 class="mb-4 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Credit note') }}</h3>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="billing_ref" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Invoice it corrects') }}</label>
                <x-ui.input type="text" name="billing_ref" id="billing_ref" value="{{ old('billing_ref') }}" />
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Required for a credit note, unless it is a volume discount.') }}</p>
            </div>

            <div>
                <label for="credit_reason" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Reason') }}</label>
                <x-ui.select name="credit_reason" id="credit_reason">
                    <option value="">{{ __('Choose for a credit note...') }}</option>
                    @foreach (\App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\PintAe::CREDIT_REASONS as $reason)
                        <option value="{{ $reason }}" @selected(old('credit_reason') === $reason)>{{ $reason === 'VD' ? 'VD · '.__('Volume discount') : $reason }}</option>
                    @endforeach
                </x-ui.select>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Required on a tax credit note: the clause of Article 61(1) of the VAT Decree-Law it falls under.') }}</p>
            </div>
        </div>
    </div>

    <div class="mb-6 rounded-lg bg-white dark:bg-gray-900 p-6 shadow">
        <h3 class="mb-4 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Totals and notes') }}</h3>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="discount_amount" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Document discount') }}</label>
                <x-ui.input type="number" step="0.01" min="0" name="discount_amount" id="discount_amount"
                       value="{{ old('discount_amount') }}" />
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Prices and the discount exclude VAT; VAT is added at the document total.') }}</p>
            </div>

            <div>
                <label for="notes" class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Notes') }}</label>
                <x-ui.textarea name="notes" id="notes" rows="3">{{ old('notes') }}</x-ui.textarea>
            </div>
        </div>
    </div>

    <div class="flex items-center gap-4">
        <x-ui.button>{{ __('Save as draft') }}</x-ui.button>
        <x-ui.button variant="quiet" :href="route('portal.invoices.index')">{{ __('Cancel') }}</x-ui.button>
    </div>
</form>
@endsection
