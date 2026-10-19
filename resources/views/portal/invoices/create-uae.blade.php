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
    // The rate each category fills in, from the module's code list (VatTreatments).
    $rates = collect($treatments->categories())->mapWithKeys(fn ($category) => [$category->code => (string) $category->rate])->all();
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

<x-ui.page-header :title="__('New invoice')" :description="__('Saved as a draft. When it is issued it takes its number and is sent to the buyer as a UAE e-invoice; a draft can be changed.')" />

{{-- The form's errors are listed by the layout's x-ui.flash. --}}

<form method="POST" action="{{ route('portal.invoices.uae.store') }}" data-invoice-form
      data-preview-url="{{ route('portal.invoices.preview') }}"
      data-default-rates="{{ json_encode($rates) }}">
    @csrf

    <div class="mb-6 rounded-card bg-surface p-6 border border-line shadow-card">
        <h3 class="mb-4 text-xs font-semibold uppercase tracking-wider text-muted">{{ __('Document') }}</h3>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <x-ui.field :label="__('Invoice number')">
                    <x-ui.input type="text" name="invoice_number" id="invoice_number" value="{{ old('invoice_number') }}" placeholder="{{ __('Numbered when issued') }}" />
                </x-ui.field>
                <p class="mt-1 text-xs text-muted">{{ __('Leave empty to take the next number from your numbering when it is issued.') }}</p>
            </div>

            <div>
                <x-ui.field :label="__('Document')">
                    <x-ui.select name="document_type" id="document_type">
                        <option value="invoice" @selected(old('document_type', 'invoice') === 'invoice')>{{ __('Invoice') }}</option>
                        <option value="credit_note" @selected(old('document_type') === 'credit_note')>{{ __('Credit note') }}</option>
                    </x-ui.select>
                </x-ui.field>
                <p class="mt-1 text-xs text-muted">{{ __('There is no debit note in the UAE: an increase is a new invoice.') }}</p>
            </div>

            <div>
                <x-ui.field :label="__('Category')">
                    <x-ui.select name="document_category" id="document_category">
                        <option value="tax" @selected(old('document_category', 'tax') === 'tax')>{{ __('Tax invoice') }}</option>
                        <option value="commercial" @selected(old('document_category') === 'commercial')>{{ __('Commercial (no VAT charged)') }}</option>
                        <option value="self_billing" @selected(old('document_category') === 'self_billing')>{{ __('Self-billing') }}</option>
                    </x-ui.select>
                </x-ui.field>
                <p class="mt-1 text-xs text-muted">{{ __('Commercial: exempt, out-of-scope or zero-rated supplies, or a seller not registered for VAT. Self-billing: you issue it as the buyer, for your supplier.') }}</p>
            </div>

            <div>
                <x-ui.field :label="__('Issue date')">
                    <x-ui.input type="date" name="issue_date" id="issue_date"
                                value="{{ old('issue_date', now()->toDateString()) }}" required />
                </x-ui.field>
            </div>

            <div>
                <x-ui.field :label="__('Supply date')">
                    <x-ui.input type="date" name="supply_date" id="supply_date" value="{{ old('supply_date') }}" />
                </x-ui.field>
            </div>

            <div>
                <x-ui.field :label="__('Supply end date')">
                    <x-ui.input type="date" name="supply_end_date" id="supply_end_date" value="{{ old('supply_end_date') }}" />
                </x-ui.field>
                <p class="mt-1 text-xs text-muted">{{ __('For a summary invoice or a continuous supply: the end of the period.') }}</p>
            </div>

            <div>
                <x-ui.field :label="__('Due date')">
                    <x-ui.input type="date" name="due_date" id="due_date" value="{{ old('due_date') }}" />
                </x-ui.field>
                <p class="mt-1 text-xs text-muted">{{ __('Required on an invoice, except a deemed supply. Not on a credit note.') }}</p>
            </div>

            <div>
                <x-ui.field :label="__('Currency')">
                    <x-ui.input type="text" name="currency" id="currency" maxlength="3" value="{{ old('currency', 'AED') }}" class="uppercase" />
                </x-ui.field>
                <p class="mt-1 text-xs text-muted">{{ __('Anything but AED needs an exchange rate to AED.') }}</p>
            </div>

            <div>
                <x-ui.field :label="__('Exchange rate to AED')">
                    <x-ui.input type="number" step="0.000001" min="0" name="exchange_rate" id="exchange_rate"
                           value="{{ old('exchange_rate') }}" />
                </x-ui.field>
            </div>

            <div>
                <x-ui.field :label="__('Payment means')">
                    <x-ui.select name="payment_means_code" id="payment_means_code">
                        <option value="10" @selected(old('payment_means_code', '10') === '10')>{{ __('Cash') }}</option>
                        <option value="30" @selected(old('payment_means_code') === '30')>{{ __('Credit transfer') }}</option>
                        {{-- PINT AE's code list of payment means used in the UAE (PintAe::PAYMENT_MEANS). --}}
                        <option value="49" @selected(old('payment_means_code') === '49')>{{ __('Direct debit') }}</option>
                        <option value="54" @selected(old('payment_means_code') === '54')>{{ __('Credit card') }}</option>
                        <option value="55" @selected(old('payment_means_code') === '55')>{{ __('Debit card') }}</option>
                        <option value="20" @selected(old('payment_means_code') === '20')>{{ __('Cheque') }}</option>
                        <option value="68" @selected(old('payment_means_code') === '68')>{{ __('Online payment service') }}</option>
                        <option value="1" @selected(old('payment_means_code') === '1')>{{ __('Instrument not defined') }}</option>
                    </x-ui.select>
                </x-ui.field>
                <p class="mt-1 text-xs text-muted">{{ __('A transfer prints the IBAN from your company details.') }}</p>
            </div>

            @include('portal.invoices.partials.references')
            @include('portal.invoices.partials.payment-terms')
        </div>
    </div>

    <div class="mb-6 rounded-card bg-surface p-6 border border-line shadow-card">
        <h3 class="mb-4 text-xs font-semibold uppercase tracking-wider text-muted">{{ __('Buyer') }}</h3>

        @if ($customers->isNotEmpty())
            {{-- Fills the fields below; it submits nothing itself. --}}
            <div class="mb-4 max-w-md">
                <x-ui.field :label="__('Saved customer')">
                    <x-ui.select id="customer_picker" data-customer-picker>
                        <option value="">{{ __('Choose to fill in the buyer...') }}</option>
                        @foreach ($customers as $customer)
                            <option value="{{ $customer->id }}" data-fields="{{ json_encode($customer->uaeBuyerFields()) }}">
                                {{ $customer->name }}{{ $customer->vat_number ? ' - '.$customer->vat_number : '' }}
                            </option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            </div>
        @endif

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <x-ui.field :label="__('Name')">
                    <x-ui.input type="text" name="buyer_name" id="buyer_name" value="{{ old('buyer_name') }}" required />
                </x-ui.field>
            </div>

            <div>
                <x-ui.field :label="__('Name in Arabic')">
                    <x-ui.input type="text" name="buyer_name_ar" id="buyer_name_ar" value="{{ old('buyer_name_ar') }}" dir="rtl" lang="ar" />
                </x-ui.field>
            </div>

            <div>
                <x-ui.field :label="__('TRN')">
                    <x-ui.input type="text" name="buyer_vat_number" id="buyer_vat_number" inputmode="numeric" maxlength="15" dir="ltr" value="{{ old('buyer_vat_number') }}" />
                </x-ui.field>
                <p class="mt-1 text-xs text-muted">{{ __('A buyer registered for VAT in the UAE: 15 digits, starting with 1 and ending with 03.') }}</p>
            </div>

            <div>
                <x-ui.field :label="__('Buyer e-invoicing address')">
                    <x-ui.select name="buyer_endpoint_choice" id="buyer_endpoint_choice">
                        <option value="own" @selected($choice === 'own')>{{ __('The buyer\'s own Peppol endpoint') }}</option>
                        <option value="not_yet" @selected($choice === 'not_yet')>{{ __('Buyer not yet on e-invoicing (0235:9900000098)') }}</option>
                        <option value="deemed" @selected($choice === 'deemed')>{{ __('Deemed supply (0235:9900000097)') }}</option>
                        <option value="export" @selected($choice === 'export')>{{ __('Export, buyer outside the UAE (0235:9900000099)') }}</option>
                    </x-ui.select>
                </x-ui.field>
                <p class="mt-1 text-xs text-muted">{{ __('The three reserved addresses are for a buyer with no Peppol endpoint of its own. A deemed supply or an export is also marked as such below.') }}</p>
            </div>

            <div>
                <x-ui.field :label="__('Buyer Peppol endpoint')">
                    <x-ui.input type="text" name="buyer_endpoint" id="buyer_endpoint" maxlength="60" dir="ltr" placeholder="0235:1234567890" value="{{ old('buyer_endpoint') }}" />
                </x-ui.field>
                <p class="mt-1 text-xs text-muted">{{ __('Used when the buyer has its own endpoint.') }}</p>
            </div>

            <div>
                <x-ui.field :label="__('Street')">
                    <x-ui.input type="text" name="buyer_address[street]" id="buyer_address_street" value="{{ old('buyer_address.street') }}" required />
                </x-ui.field>
            </div>

            <div>
                <x-ui.field :label="__('Building or unit')">
                    <x-ui.input type="text" name="buyer_address[building_number]" id="buyer_address_building_number" value="{{ old('buyer_address.building_number') }}" />
                </x-ui.field>
            </div>

            <div>
                <x-ui.field :label="__('City')">
                    <x-ui.input type="text" name="buyer_address[city]" id="buyer_address_city" value="{{ old('buyer_address.city') }}" required />
                </x-ui.field>
            </div>

            <div>
                <x-ui.field :label="__('Emirate or region')">
                    <x-ui.input type="text" name="buyer_subdivision" id="buyer_subdivision" maxlength="50" list="emirate_codes" dir="ltr" value="{{ old('buyer_subdivision') }}" required />
                </x-ui.field>
                <datalist id="emirate_codes">
                    @include('portal.partials.emirate-options', ['selected' => null])
                </datalist>
                <p class="mt-1 text-xs text-muted">{{ __('In the UAE, the emirate code: AUH, DXB, SHJ, AJM, UAQ, RAK or FUJ. Abroad, the buyer\'s region.') }}</p>
            </div>

            <div>
                <x-ui.field :label="__('P.O. box or postal code')">
                    <x-ui.input type="text" name="buyer_address[postal_code]" id="buyer_address_postal_code" value="{{ old('buyer_address.postal_code') }}" />
                </x-ui.field>
            </div>

            <div>
                <x-ui.field :label="__('Country')">
                    <x-ui.input type="text" name="buyer_address[country_code]" id="buyer_address_country_code" maxlength="2"
                           value="{{ old('buyer_address.country_code', 'AE') }}" class="uppercase" />
                </x-ui.field>
            </div>
        </div>

        {{-- Required on a commercial document (it carries no TRN to name the buyer by). --}}
        <h4 class="mt-6 mb-3 text-sm font-medium text-fg">{{ __('Buyer legal registration') }}</h4>
        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <x-ui.field :label="__('Type')">
                    <x-ui.select name="buyer_legal_registration[type]" id="buyer_legal_registration_type">
                        <option value="">{{ __('None') }}</option>
                        @include('portal.partials.legal-registration-options', ['selected' => old('buyer_legal_registration.type')])
                    </x-ui.select>
                </x-ui.field>
            </div>
            <div>
                <x-ui.field :label="__('Number')">
                    <x-ui.input type="text" name="buyer_legal_registration[id]" id="buyer_legal_registration_id" maxlength="100" dir="ltr" value="{{ old('buyer_legal_registration.id') }}" />
                </x-ui.field>
            </div>
            <div>
                <x-ui.field :label="__('Issuing authority')">
                    <x-ui.input type="text" name="buyer_legal_registration[authority]" id="buyer_legal_registration_authority" maxlength="255" value="{{ old('buyer_legal_registration.authority') }}" />
                </x-ui.field>
            </div>
        </div>
        <p class="mt-1 text-xs text-muted">{{ __('Required on a commercial document.') }}</p>

        <div class="mt-3">
            <x-ui.checkbox name="save_customer" value="1" :checked="(bool) old('save_customer')">{{ __('Save this buyer to Customers') }}</x-ui.checkbox>
        </div>
    </div>

    <x-ui.section :title="__('Transaction type')" :description="__('Tick each that applies. Most invoices need none.')" collapse class="mb-6">

        {{-- Positions in the eight-character transaction-type code
             (BTAE-02), in the code's order. --}}
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($flagLabels as $position => $label)
                <x-ui.checkbox name="flags[]" value="{{ $position }}" :checked="in_array((string) $position, $flags, true)">{{ $label }}</x-ui.checkbox>
            @endforeach
        </div>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <div>
                <x-ui.field :label="__('Beneficiary ID (free zone)')">
                    <x-ui.input type="text" name="beneficiary_id" id="beneficiary_id" maxlength="50" dir="ltr" value="{{ old('beneficiary_id') }}" />
                </x-ui.field>
            </div>
            <div>
                <x-ui.field :label="__('Principal TRN (disclosed agent billing)')">
                    <x-ui.input type="text" name="principal_id" id="principal_id" inputmode="numeric" maxlength="15" dir="ltr" value="{{ old('principal_id') }}" />
                </x-ui.field>
            </div>
        </div>

        {{-- An export or an e-commerce supply states where the goods go;
             left empty, the buyer's address is used. --}}
        <h4 class="mt-6 mb-3 text-sm font-medium text-fg">{{ __('Delivery address (optional)') }}</h4>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <div>
                <x-ui.field :label="__('Street')">
                    <x-ui.input type="text" name="delivery_address[street]" id="delivery_address_street" value="{{ old('delivery_address.street') }}" />
                </x-ui.field>
            </div>
            <div>
                <x-ui.field :label="__('City')">
                    <x-ui.input type="text" name="delivery_address[city]" id="delivery_address_city" value="{{ old('delivery_address.city') }}" />
                </x-ui.field>
            </div>
            <div>
                <x-ui.field :label="__('Emirate or region')">
                    <x-ui.input type="text" name="delivery_address[subdivision]" id="delivery_address_subdivision" maxlength="50" value="{{ old('delivery_address.subdivision') }}" />
                </x-ui.field>
            </div>
            <div>
                <x-ui.field :label="__('P.O. box or postal code')">
                    <x-ui.input type="text" name="delivery_address[postal_code]" id="delivery_address_postal_code" value="{{ old('delivery_address.postal_code') }}" />
                </x-ui.field>
            </div>
            <div>
                <x-ui.field :label="__('Country')">
                    <x-ui.input type="text" name="delivery_address[country_code]" id="delivery_address_country_code" maxlength="2" class="uppercase" value="{{ old('delivery_address.country_code') }}" />
                </x-ui.field>
            </div>
        </div>
    </x-ui.section>

    <div class="mb-6 rounded-card bg-surface p-6 border border-line shadow-card">
        <div class="mb-4 flex items-baseline justify-between">
            <h3 class="text-xs font-semibold uppercase tracking-wider text-muted">{{ __('Lines') }}</h3>
            <x-ui.button type="button" size="sm" variant="secondary" data-add-line>{{ __('Add a line') }}</x-ui.button>
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

    <div class="mb-6 rounded-card bg-surface p-6 border border-line shadow-card">
        <h3 class="mb-4 text-xs font-semibold uppercase tracking-wider text-muted">{{ __('Credit note') }}</h3>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <x-ui.field :label="__('Invoice it corrects')">
                    <x-ui.input type="text" name="billing_ref" id="billing_ref" value="{{ old('billing_ref') }}" />
                </x-ui.field>
                <p class="mt-1 text-xs text-muted">{{ __('Required for a credit note, unless it is a volume discount.') }}</p>
            </div>

            <div>
                <x-ui.field :label="__('Reason')">
                    <x-ui.select name="credit_reason" id="credit_reason">
                        <option value="">{{ __('Choose for a credit note...') }}</option>
                        @foreach (\App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\PintAe::CREDIT_REASONS as $reason)
                            <option value="{{ $reason }}" @selected(old('credit_reason') === $reason)>{{ $reason === 'VD' ? 'VD · '.__('Volume discount') : $reason }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <p class="mt-1 text-xs text-muted">{{ __('Required on a tax credit note: the clause of Article 61(1) of the VAT Decree-Law it falls under.') }}</p>
            </div>
        </div>
    </div>

    @include('portal.invoices.partials.charges', [
        'chargePresets' => $chargePresets ?? [],
        'chargeCategories' => ['S' => __('Standard-rated'), 'Z' => __('Zero-rated'), 'E' => __('Exempt'), 'O' => __('Out of scope')],
        'standardRate' => \App\Domains\Compliance\Countries\UnitedArabEmirates\Rules\PintAe::STANDARD_RATE,
    ])

    @include('portal.invoices.partials.vat-summary')

    {{-- Optional: folded away on a phone until wanted, opened by an error inside. --}}
    <x-ui.section :title="__('Totals and notes')" collapse class="mb-6">

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <x-ui.field :label="__('Document discount')">
                    <x-ui.input type="number" step="0.01" min="0" name="discount_amount" id="discount_amount"
                           value="{{ old('discount_amount') }}" />
                </x-ui.field>
                <p class="mt-1 text-xs text-muted">{{ __('Prices and the discount exclude VAT; VAT is added at the document total.') }}</p>
            </div>

            <div>
                <x-ui.field :label="__('Notes')">
                    <x-ui.textarea name="notes" id="notes" rows="3">{{ old('notes') }}</x-ui.textarea>
                </x-ui.field>
            </div>
        </div>
    </x-ui.section>

    <div class="flex items-center gap-4">
        <x-ui.button>{{ __('Save as draft') }}</x-ui.button>
        <x-ui.button variant="ghost" :href="route('portal.invoices.index')">{{ __('Cancel') }}</x-ui.button>
    </div>
</form>
@endsection
