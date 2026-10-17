@extends('layouts.portal')

@section('title', __('New invoice - :organization', ['organization' => $organization->name ?? __('Portal')]))

@section('content')
@php
    // The rows to render. A failed submission comes back with what was typed;
    // otherwise one empty row, because one line is the common case and an
    // empty form with no row to type in reads as broken.
    $rows = old('lines', [[]]);
@endphp

<div class="mb-6">
    <h1 class="text-2xl font-semibold tracking-tight text-fg">{{ __('New invoice') }}</h1>
    <p class="mt-1 max-w-3xl text-sm text-muted">
        {{ __('Saved as a draft. It has no counter, no hash and no QR until it is issued, and a draft can be changed.') }}
    </p>
    <p class="mt-2 text-sm text-muted">
        {{ __('The Arabic fields are optional. ZATCA accepts Latin characters for names of people, places and products, so a buyer or a product named in English is already compliant — fill these in when the buyer or the product has an Arabic name of its own, and it will appear on the invoice and in the QR code.') }}
    </p>
</div>

@if ($errors->any())
    <div class="mb-6 rounded-lg border border-danger-line bg-danger-soft p-4">
        <p class="text-sm font-semibold text-danger">
            {{ $errors->count() === 1 ? __('1 problem to fix before this can be saved') : __(':count problems to fix before this can be saved', ['count' => $errors->count()]) }}
        </p>
        <ul class="mt-2 list-disc space-y-1 ps-5 text-sm text-danger">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ route('portal.invoices.store') }}" data-invoice-form>
    @csrf

    <div class="mb-6 rounded-card bg-surface p-6 border border-line shadow-card">
        <h3 class="mb-4 text-xs font-semibold uppercase tracking-wider text-muted">{{ __('Document') }}</h3>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <label for="invoice_number" class="block text-sm font-medium text-fg">{{ __('Invoice number') }}</label>
                <x-ui.input type="text" name="invoice_number" id="invoice_number" value="{{ old('invoice_number') }}" placeholder="{{ __('Numbered when issued') }}" />
                <p class="mt-1 text-xs text-muted">{{ __('Leave empty to take the next number from your numbering when it is issued.') }}</p>
            </div>

            <div>
                <label for="type" class="block text-sm font-medium text-fg">{{ __('Type') }}</label>
                <x-ui.select name="type" id="type">
                    <option value="simplified" @selected(old('type', 'simplified') === 'simplified')>{{ __('Simplified (B2C)') }}</option>
                    <option value="standard" @selected(old('type') === 'standard')>{{ __('Standard (B2B)') }}</option>
                </x-ui.select>
                <p class="mt-1 text-xs text-muted">{{ __('Standard needs the buyer\'s VAT number and address.') }}</p>
            </div>

            <div>
                <label for="document_type" class="block text-sm font-medium text-fg">{{ __('Document') }}</label>
                <x-ui.select name="document_type" id="document_type">
                    <option value="invoice" @selected(old('document_type', 'invoice') === 'invoice')>{{ __('Invoice') }}</option>
                    <option value="credit_note" @selected(old('document_type') === 'credit_note')>{{ __('Credit note') }}</option>
                    <option value="debit_note" @selected(old('document_type') === 'debit_note')>{{ __('Debit note') }}</option>
                    <option value="prepayment" @selected(old('document_type') === 'prepayment')>{{ __('Prepayment') }}</option>
                </x-ui.select>
            </div>

            <div>
                <label for="issue_date" class="block text-sm font-medium text-fg">{{ __('Issue date') }}</label>
                <x-ui.input type="date" name="issue_date" id="issue_date"
                            value="{{ old('issue_date', now()->toDateString()) }}" required />
            </div>

            <div>
                <label for="supply_date" class="block text-sm font-medium text-fg">{{ __('Supply date') }}</label>
                <x-ui.input type="date" name="supply_date" id="supply_date" value="{{ old('supply_date') }}" />
            </div>

            <div>
                <label for="currency" class="block text-sm font-medium text-fg">{{ __('Currency') }}</label>
                <x-ui.input type="text" name="currency" id="currency" maxlength="3" value="{{ old('currency', 'SAR') }}" class="uppercase" />
                <p class="mt-1 text-xs text-muted">{{ __('Anything but SAR needs an exchange rate.') }}</p>
            </div>

            <div>
                <label for="exchange_rate" class="block text-sm font-medium text-fg">{{ __('Exchange rate') }}</label>
                <x-ui.input type="number" step="0.000001" min="0" name="exchange_rate" id="exchange_rate"
                       value="{{ old('exchange_rate') }}" />
            </div>

            <div>
                <label for="billing_ref" class="block text-sm font-medium text-fg">{{ __('Billed document') }}</label>
                <x-ui.input type="text" name="billing_ref" id="billing_ref" value="{{ old('billing_ref') }}" />
                <p class="mt-1 text-xs text-muted">{{ __('Required for a credit or debit note.') }}</p>
            </div>

            {{-- The reasons ZATCA's data dictionary lists for KSA-10, read
                 from App\Domains\Invoice\Support\AdjustmentReason. Each is
                 stored and printed in both languages, exactly as written
                 there, so the option shows what the note will say. --}}
            <div class="sm:col-span-2">
                <label for="adjustment_reason" class="block text-sm font-medium text-fg">{{ __('Reason') }}</label>
                <x-ui.select name="adjustment_reason" id="adjustment_reason">
                    <option value="">{{ __('Choose for a credit or debit note...') }}</option>
                    @foreach (\App\Domains\Invoice\Support\AdjustmentReason::values() as $reason)
                        <option value="{{ $reason }}" @selected(old('adjustment_reason') === $reason)>{{ $reason }}</option>
                    @endforeach
                </x-ui.select>
                {{-- VAT Implementing Regulations, Article 54: a note is issued
                     within fifteen days of the end of the month in which the
                     change happened. The date of the change is not something
                     the form knows, so it says so rather than checks. --}}
                <p class="mt-1 text-xs text-muted">{{ __('Issue a note within 15 days of the end of the month in which the change happened. One note may correct several invoices: separate their numbers with commas.') }}</p>
            </div>
        </div>
    </div>

    <div class="mb-6 rounded-card bg-surface p-6 border border-line shadow-card">
        <h3 class="mb-4 text-xs font-semibold uppercase tracking-wider text-muted">{{ __('Buyer') }}</h3>

        @if ($customers->isNotEmpty())
            {{-- Fills the fields below; it submits nothing itself, so what is
                 sent is what the fields say after any edit. --}}
            <div class="mb-4 max-w-md">
                <label for="customer_picker" class="block text-sm font-medium text-fg">{{ __('Saved customer') }}</label>
                <x-ui.select id="customer_picker" data-customer-picker>
                    <option value="">{{ __('Choose to fill in the buyer...') }}</option>
                    @foreach ($customers as $customer)
                        <option value="{{ $customer->id }}" data-fields="{{ json_encode($customer->buyerFields()) }}">
                            {{ $customer->name }}{{ $customer->vat_number ? ' - '.$customer->vat_number : '' }}
                        </option>
                    @endforeach
                </x-ui.select>
            </div>
        @endif

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <label for="buyer_name" class="block text-sm font-medium text-fg">{{ __('Name') }}</label>
                {{-- Not required here. ZATCA's BR-KSA-25 owes a name on a
                     simplified invoice only when a line is exempt as private
                     education or healthcare, and the server knows which lines
                     those are. Required in the browser, every cash sale made
                     the cashier invent a buyer. --}}
                <x-ui.input type="text" name="buyer_name" id="buyer_name" value="{{ old('buyer_name') }}" />
                <span class="mt-1 block text-xs text-muted">{{ __('Leave empty for a walk-in customer on a simplified invoice.') }}</span>
            </div>

            <div>
                <label for="buyer_name_ar" class="block text-sm font-medium text-fg">
                    {{ __('Name in Arabic') }}
                </label>
                <x-ui.input type="text" name="buyer_name_ar" id="buyer_name_ar" value="{{ old('buyer_name_ar') }}"
                       dir="rtl" lang="ar" />
                <p class="mt-1 text-xs text-muted">{{ __('Optional — a Latin name is accepted for a proper name.') }}</p>
            </div>

            <div>
                <label for="buyer_vat_number" class="block text-sm font-medium text-fg">{{ __('VAT number') }}</label>
                <x-ui.input type="text" name="buyer_vat_number" id="buyer_vat_number" value="{{ old('buyer_vat_number') }}" />
            </div>

            {{-- BT-46: a standard invoice to a buyer with no VAT number
                 identifies them another way (BR-KSA-81), commonly by
                 commercial registration. --}}
            <div>
                <label for="buyer_id_scheme" class="block text-sm font-medium text-fg">{{ __('Other identifier (if no VAT number)') }}</label>
                <x-ui.select name="buyer_id_scheme" id="buyer_id_scheme">
                    <option value="">{{ __('None') }}</option>
                    @foreach (\App\Domains\Compliance\Countries\SaudiArabia\Rules\FatooraConfig::BUYER_ID_SCHEME_LABELS as $scheme => [$english, $arabicLabel])
                        <option value="{{ $scheme }}" @selected(old('buyer_id_scheme') === $scheme)>{{ app()->getLocale() === 'ar' ? $arabicLabel : $english }}</option>
                    @endforeach
                </x-ui.select>
            </div>

            <div>
                <label for="buyer_id" class="block text-sm font-medium text-fg">{{ __('Identifier number') }}</label>
                <x-ui.input type="text" name="buyer_id" id="buyer_id" maxlength="50" value="{{ old('buyer_id') }}" />
            </div>

            <div>
                <label for="buyer_address_street" class="block text-sm font-medium text-fg">{{ __('Street') }}</label>
                <x-ui.input type="text" name="buyer_address[street]" id="buyer_address_street"
                       value="{{ old('buyer_address.street') }}" />
            </div>

            <div>
                <label for="buyer_address_building_number" class="block text-sm font-medium text-fg">{{ __('Building number') }}</label>
                <x-ui.input type="text" name="buyer_address[building_number]" id="buyer_address_building_number"
                       value="{{ old('buyer_address.building_number') }}" />
            </div>

            <div>
                <label for="buyer_address_city" class="block text-sm font-medium text-fg">{{ __('City') }}</label>
                <x-ui.input type="text" name="buyer_address[city]" id="buyer_address_city"
                       value="{{ old('buyer_address.city') }}" />
            </div>

            <div>
                <label for="buyer_address_district" class="block text-sm font-medium text-fg">{{ __('District') }}</label>
                <x-ui.input type="text" name="buyer_address[district]" id="buyer_address_district"
                       value="{{ old('buyer_address.district') }}" />
            </div>

            <div>
                <label for="buyer_address_postal_code" class="block text-sm font-medium text-fg">{{ __('Postal code') }}</label>
                <x-ui.input type="text" name="buyer_address[postal_code]" id="buyer_address_postal_code"
                       value="{{ old('buyer_address.postal_code') }}" />
            </div>

            <div>
                <label for="buyer_address_country_code" class="block text-sm font-medium text-fg">{{ __('Country') }}</label>
                <x-ui.input type="text" name="buyer_address[country_code]" id="buyer_address_country_code" maxlength="2"
                       value="{{ old('buyer_address.country_code', 'SA') }}" class="uppercase" />
            </div>
        </div>

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

    {{-- Optional: folded away on a phone until wanted, opened by an error inside. --}}
    <x-ui.section :title="__('Totals and notes')" collapse class="mb-6">

        <div class="grid gap-4 sm:grid-cols-2">
            {{-- A select, not a checkbox: an unticked box sends nothing, which
                 would quietly fall back to the organization's own setting. --}}
            @php($inclusive = (string) old('prices_include_vat', $organization->prices_include_vat ? '1' : '0'))
            <div class="sm:col-span-2">
                <label for="prices_include_vat" class="block text-sm font-medium text-fg">{{ __('Prices') }}</label>
                <x-ui.select name="prices_include_vat" id="prices_include_vat">
                    <option value="0" @selected($inclusive === '0')>{{ __('Exclude VAT - VAT is added to them') }}</option>
                    <option value="1" @selected($inclusive === '1')>{{ __('Include VAT - the customer pays exactly these') }}</option>
                </x-ui.select>
                <p class="mt-1 text-xs text-muted">{{ __('Applies to the unit prices and the discount.') }}</p>
            </div>

            <div>
                <label for="discount_amount" class="block text-sm font-medium text-fg">{{ __('Document discount') }}</label>
                <x-ui.input type="number" step="0.01" min="0" name="discount_amount" id="discount_amount"
                       value="{{ old('discount_amount') }}" />
                <p class="mt-1 text-xs text-muted">{{ __('Apportioned across the tax categories, not taken off the total.') }}</p>
            </div>

            <div>
                <label for="notes" class="block text-sm font-medium text-fg">{{ __('Notes') }}</label>
                <x-ui.textarea name="notes" id="notes" rows="3">{{ old('notes') }}</x-ui.textarea>
            </div>
        </div>
    </x-ui.section>

    <div class="flex items-center gap-4">
        <x-ui.button>{{ __('Save as draft') }}</x-ui.button>
        <x-ui.button variant="ghost" :href="route('portal.invoices.index')">{{ __('Cancel') }}</x-ui.button>
    </div>
</form>
@endsection
