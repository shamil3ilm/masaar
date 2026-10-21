@extends('layouts.portal')

@section('title', __('New invoice - :organization', ['organization' => $organization->name ?? __('Portal')]))

@section('content')
@php
    // The rows to render. A failed submission comes back with what was typed;
    // otherwise one empty row, because one line is the common case and an
    // empty form with no row to type in reads as broken.
    $rows = old('lines', [[]]);
@endphp

<x-ui.page-header :title="__('New invoice')" :description="__('Saved as a draft. It has no counter, no hash and no QR until it is issued, and a draft can be changed.')" class="mb-2" />
<div class="mb-6">
    <p class="max-w-3xl text-sm text-muted">
        {{ __('The invoice must be in Arabic: the description of each line, any notes, payment terms and charge reasons, the reason for a credit or debit note, and on a standard invoice the name of the buyer. English may go beside it. A saved customer or product fills in its Arabic.') }}
    </p>
</div>

{{-- The form's errors are listed by the layout's x-ui.flash. --}}

<form method="POST" action="{{ route('portal.invoices.store') }}" data-invoice-form
      data-preview-url="{{ route('portal.invoices.preview') }}"
      data-default-rates="{{ json_encode(collect($treatments->categories())->mapWithKeys(fn ($category) => [$category->code => (string) $category->rate])) }}">
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
                <x-ui.field :label="__('Type')">
                    <x-ui.select name="type" id="type">
                        {{-- Starts on what the business type suggests (BusinessTypes). --}}
                        @php($invoiceType = old('type', $businessType?->invoiceType ?? 'simplified'))
                        <option value="simplified" @selected($invoiceType === 'simplified')>{{ __('Simplified (B2C)') }}</option>
                        <option value="standard" @selected($invoiceType === 'standard')>{{ __('Standard (B2B)') }}</option>
                    </x-ui.select>
                </x-ui.field>
                <p class="mt-1 text-xs text-muted">{{ __('Standard needs the buyer\'s VAT number and address.') }}</p>
            </div>

            <div>
                <x-ui.field :label="__('Document')">
                    <x-ui.select name="document_type" id="document_type">
                        <option value="invoice" @selected(old('document_type', 'invoice') === 'invoice')>{{ __('Invoice') }}</option>
                        <option value="credit_note" @selected(old('document_type') === 'credit_note')>{{ __('Credit note') }}</option>
                        <option value="debit_note" @selected(old('document_type') === 'debit_note')>{{ __('Debit note') }}</option>
                        <option value="prepayment" @selected(old('document_type') === 'prepayment')>{{ __('Prepayment') }}</option>
                    </x-ui.select>
                </x-ui.field>
            </div>

            <div>
                <x-ui.field :label="__('Issue date')">
                    <x-ui.date-input name="issue_date" id="issue_date"
                                value="{{ old('issue_date', now()->toDateString()) }}" required />
                </x-ui.field>
            </div>

            <div>
                <x-ui.field :label="__('Supply date')">
                    {{-- BR-KSA-15: a standard invoice carries its supply date; today unless changed. --}}
                    <x-ui.date-input name="supply_date" id="supply_date" value="{{ old('supply_date', now('Asia/Riyadh')->toDateString()) }}" />
                </x-ui.field>
            </div>

            <div>
                <x-ui.field :label="__('Supply end date')">
                    <x-ui.date-input name="supply_end_date" id="supply_end_date" value="{{ old('supply_end_date') }}" />
                </x-ui.field>
                <p class="mt-1 text-xs text-muted">{{ __('For a supply over a period, such as a subscription.') }}</p>
            </div>

            <div>
                <x-ui.field :label="__('Currency')">
                    <x-ui.input type="text" name="currency" id="currency" maxlength="3" value="{{ old('currency', 'SAR') }}" class="uppercase" />
                </x-ui.field>
                <p class="mt-1 text-xs text-muted">{{ __('Anything but SAR needs an exchange rate.') }}</p>
            </div>

            <div>
                <x-ui.field :label="__('Exchange rate')">
                    <x-ui.input type="number" step="0.000001" min="0" name="exchange_rate" id="exchange_rate"
                           value="{{ old('exchange_rate') }}" />
                </x-ui.field>
            </div>

            {{-- BT-81, the codes ZATCA names (FatooraConfig::PAYMENT_MEANS). --}}
            <div>
                <x-ui.field :label="__('Payment means')">
                    <x-ui.select name="payment_means_code" id="payment_means_code">
                        @foreach (['10' => __('Cash'), '30' => __('Credit transfer'), '42' => __('Payment to bank account'), '48' => __('Bank card'), '1' => __('Instrument not defined')] as $code => $label)
                            <option value="{{ $code }}" @selected((string) old('payment_means_code', $businessType?->paymentMeans ?? '10') === (string) $code)>{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            </div>

            <div>
                <x-ui.field :label="__('Billed document')">
                    <x-ui.input type="text" name="billing_ref" id="billing_ref" value="{{ old('billing_ref') }}" />
                </x-ui.field>
                <p class="mt-1 text-xs text-muted">{{ __('Required for a credit or debit note.') }}</p>
            </div>

            <div>
                <x-ui.field :label="__('Due date')">
                    <x-ui.date-input name="due_date" id="due_date" value="{{ old('due_date') }}" />
                </x-ui.field>
            </div>

            @include('portal.invoices.partials.references')
            @include('portal.invoices.partials.payment-terms')

            <div>
                <x-ui.field :label="__('Settles prepayment invoices')">
                    <x-ui.input type="text" name="prepayment_numbers" id="prepayment_numbers" value="{{ old('prepayment_numbers') }}" />
                </x-ui.field>
                <p class="mt-1 text-xs text-muted">{{ __('On a final invoice: the numbers of the prepayment invoices it settles, separated by commas.') }}</p>
            </div>

            {{-- The reasons ZATCA's data dictionary lists for KSA-10, read
                 from App\Domains\Invoice\Support\AdjustmentReason. Each is
                 stored and printed in both languages, exactly as written
                 there, so the option shows what the note will say. --}}
            <div class="sm:col-span-2">
                <x-ui.field :label="__('Reason')">
                    <x-ui.select name="adjustment_reason" id="adjustment_reason">
                        <option value="">{{ __('Choose for a credit or debit note...') }}</option>
                        @foreach (\App\Domains\Invoice\Support\AdjustmentReason::values() as $reason)
                            <option value="{{ $reason }}" @selected(old('adjustment_reason') === $reason)>{{ $reason }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                {{-- VAT Implementing Regulations, Article 54: a note is issued
                     within fifteen days of the end of the month in which the
                     change happened. The date of the change is not something
                     the form knows, so it says so rather than checks. --}}
                <p class="mt-1 text-xs text-muted">{{ __('Issue a note within 15 days of the end of the month in which the change happened. One note may correct several invoices: separate their numbers with commas.') }}</p>
            </div>
        </div>

        {{-- KSA-2 positions 3 to 7, and 9 for a government buyer. An
             unticked box sends nothing, which is what "not this kind" is. --}}
        <fieldset class="mt-4">
            <legend class="block text-sm font-medium text-fg">{{ __('Transaction type') }}</legend>
            <div class="mt-1 flex flex-wrap gap-x-6">
                @foreach (['is_export' => __('Export'), 'is_summary' => __('Summary invoice'), 'is_nominal' => __('Nominal supply'), 'is_third_party' => __('Third party'), 'is_self_billed' => __('Self-billed'), 'is_government' => __('Government buyer (B2G)')] as $flag => $label)
                    <x-ui.checkbox name="{{ $flag }}" value="1" :checked="(bool) old($flag)">{{ $label }}</x-ui.checkbox>
                @endforeach
            </div>
            <p class="mt-1 text-xs text-muted">{{ __('A government buyer needs the contract the sale falls under, in Contract.') }}</p>
        </fieldset>
    </div>

    <div class="mb-6 rounded-card bg-surface p-6 border border-line shadow-card">
        <h3 class="mb-4 text-xs font-semibold uppercase tracking-wider text-muted">{{ __('Buyer') }}</h3>

        @if ($customers->isNotEmpty())
            {{-- Fills the fields below; it submits nothing itself, so what is
                 sent is what the fields say after any edit. --}}
            <div class="mb-4 max-w-md">
                <x-ui.field :label="__('Saved customer')">
                    <x-ui.select id="customer_picker" data-customer-picker :source="route('portal.customers.lookup')">
                        <option value="">{{ __('Choose to fill in the buyer...') }}</option>
                        @foreach ($customers as $customer)
                            <option value="{{ $customer->id }}" data-search="{{ $customer->name_ar }}" data-fields="{{ json_encode($customer->buyerFields()) }}">
                                {{ $customer->name }}{{ $customer->vat_number ? ' - '.$customer->vat_number : '' }}
                            </option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            </div>
        @endif

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div>
                {{-- Not required here. ZATCA's BR-KSA-25 owes a name on a
                     simplified invoice only when a line is exempt as private
                     education or healthcare, and the server knows which lines
                     those are. Required in the browser, every cash sale made
                     the cashier invent a buyer. --}}
                <x-ui.field :label="__('Name')">
                    <x-ui.input type="text" name="buyer_name" id="buyer_name" value="{{ old('buyer_name') }}" />
                </x-ui.field>
                <span class="mt-1 block text-xs text-muted">{{ __('Leave empty for a walk-in customer on a simplified invoice.') }}</span>
            </div>

            <div>
                <x-ui.field :label="__('Name in Arabic')">
                    <x-ui.input type="text" name="buyer_name_ar" id="buyer_name_ar" value="{{ old('buyer_name_ar') }}"
                           dir="rtl" lang="ar" />
                </x-ui.field>
                <p class="mt-1 text-xs text-muted">{{ __('Required on a standard invoice.') }}</p>
            </div>

        </div>

        {{-- At a till the buyer is usually nobody in particular, so a
             business type that sells over a counter has these folded away
             (BusinessType::$buyerFields). Folded, not removed: one click
             opens them, and an error inside or a standard invoice opens
             them by itself. --}}
        <x-ui.disclosure :summary="__('VAT number, other identifier and address')" class="mt-2 border-b-0 py-3"
                         :open="($businessType?->buyerFields ?? 'full') === 'full' || $errors->any() || old('type', $businessType?->invoiceType) === 'standard'">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <x-ui.field :label="__('VAT number')">
                    <x-ui.input type="text" name="buyer_vat_number" id="buyer_vat_number" value="{{ old('buyer_vat_number') }}" />
                </x-ui.field>
            </div>

            {{-- BT-46: a standard invoice to a buyer with no VAT number
                 identifies them another way (BR-KSA-81), commonly by
                 commercial registration. --}}
            <div>
                <x-ui.field :label="__('Other identifier (if no VAT number)')">
                    <x-ui.select name="buyer_id_scheme" id="buyer_id_scheme">
                        <option value="">{{ __('None') }}</option>
                        @foreach (\App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraConfig::BUYER_ID_SCHEME_LABELS as $scheme => [$english, $arabicLabel])
                            <option value="{{ $scheme }}" @selected(old('buyer_id_scheme') === $scheme)>{{ app()->getLocale() === 'ar' ? $arabicLabel : $english }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            </div>

            <div>
                <x-ui.field :label="__('Identifier number')">
                    <x-ui.input type="text" name="buyer_id" id="buyer_id" maxlength="50" value="{{ old('buyer_id') }}" />
                </x-ui.field>
            </div>

            <div>
                <x-ui.field :label="__('Street')">
                    <x-ui.input type="text" name="buyer_address[street]" id="buyer_address_street"
                           value="{{ old('buyer_address.street') }}" />
                </x-ui.field>
            </div>

            <div>
                <x-ui.field :label="__('Building number')">
                    <x-ui.input type="text" name="buyer_address[building_number]" id="buyer_address_building_number"
                           value="{{ old('buyer_address.building_number') }}" />
                </x-ui.field>
            </div>

            <div>
                <x-ui.field :label="__('City')">
                    <x-ui.input type="text" name="buyer_address[city]" id="buyer_address_city"
                           value="{{ old('buyer_address.city') }}" />
                </x-ui.field>
            </div>

            <div>
                <x-ui.field :label="__('District')">
                    <x-ui.input type="text" name="buyer_address[district]" id="buyer_address_district"
                           value="{{ old('buyer_address.district') }}" />
                </x-ui.field>
            </div>

            <div>
                <x-ui.field :label="__('Postal code')">
                    <x-ui.input type="text" name="buyer_address[postal_code]" id="buyer_address_postal_code"
                           value="{{ old('buyer_address.postal_code') }}" />
                </x-ui.field>
            </div>

            <div>
                <x-ui.field :label="__('Country')">
                    <x-ui.country-select name="buyer_address[country_code]" id="buyer_address_country_code"
                           :value="old('buyer_address.country_code', 'SA')" />
                </x-ui.field>
            </div>
        </div>
        </x-ui.disclosure>

        <div class="mt-3">
            <x-ui.checkbox name="save_customer" value="1" :checked="(bool) old('save_customer')">{{ __('Save this buyer to Customers') }}</x-ui.checkbox>
        </div>
    </div>

    <div class="mb-6 rounded-card bg-surface p-6 border border-line shadow-card">
        <div class="mb-4 flex items-baseline justify-between">
            <h3 class="text-xs font-semibold uppercase tracking-wider text-muted">{{ __('Lines') }}</h3>
            <x-ui.button type="button" size="sm" variant="secondary" data-add-line>{{ __('Add a line') }}</x-ui.button>
        </div>

        <div data-line-rows class="space-y-4">
            @foreach ($rows as $index => $row)
                @include('portal.invoices.partials.line-row', ['index' => $index, 'row' => $row])
            @endforeach
        </div>

        {{-- Cloned by resources/js/invoice-lines.js. Rendered from the same
             partial as the rows above, so the two cannot drift. --}}
        <template data-line-template>
            @include('portal.invoices.partials.line-row', ['index' => 0, 'row' => []])
        </template>
    </div>

    @include('portal.invoices.partials.charges', [
        'chargePresets' => $chargePresets ?? [],
        'chargeCategories' => ['S' => __('Standard-rated'), 'Z' => __('Zero-rated'), 'E' => __('Exempt'), 'O' => __('Out of scope')],
        'standardRate' => '15',
    ])

    @include('portal.invoices.partials.vat-summary')

    {{-- Optional: folded away on a phone until wanted, opened by an error inside. --}}
    <x-ui.section :title="__('Totals and notes')" collapse class="mb-6">

        <div class="grid gap-4 sm:grid-cols-2">
            {{-- A select, not a checkbox: an unticked box sends nothing, which
                 would quietly fall back to the organization's own setting. --}}
            @php($inclusive = (string) old('prices_include_vat', ($pricesIncludeVat ?? $organization->prices_include_vat) ? '1' : '0'))
            <div class="sm:col-span-2">
                <x-ui.field :label="__('Prices')">
                    <x-ui.select name="prices_include_vat" id="prices_include_vat">
                        <option value="0" @selected($inclusive === '0')>{{ __('Exclude VAT - VAT is added to them') }}</option>
                        <option value="1" @selected($inclusive === '1')>{{ __('Include VAT - the customer pays exactly these') }}</option>
                    </x-ui.select>
                </x-ui.field>
                <p class="mt-1 text-xs text-muted">{{ __('Applies to the unit prices and the discount.') }}</p>
            </div>

            <div>
                <x-ui.field :label="__('Document discount')">
                    <x-ui.input type="number" step="0.01" min="0" name="discount_amount" id="discount_amount"
                           value="{{ old('discount_amount') }}" />
                </x-ui.field>
                <p class="mt-1 text-xs text-muted">{{ __('Apportioned across the tax categories, not taken off the total.') }}</p>
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

    {{-- A sale billed to the patient and their insurer: a simplified invoice
         for the patient's share, a tax invoice to the insurer for its own.
         After the form's own button, so Enter still saves an invoice. --}}
    @if ($splits ?? false)
        <div class="mt-6">
            @include('portal.invoices.partials.split-sale', ['insurers' => $insurers ?? collect()])
        </div>
    @endif
</form>
@endsection
