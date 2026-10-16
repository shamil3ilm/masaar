@extends('layouts.portal')

@section('title', 'New invoice - ' . ($organization->name ?? 'Portal'))

@section('content')
@php
    // The rows to render. A failed submission comes back with what was typed;
    // otherwise one empty row, because one line is the common case and an
    // empty form with no row to type in reads as broken.
    $rows = old('lines', [[]]);
@endphp

<div class="mb-6">
    <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">New invoice</h2>
    <p class="text-gray-500 dark:text-gray-400">
        Saved as a draft. It has no counter, no hash and no QR until it is issued, and a draft can be changed.
    </p>
    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
        The Arabic fields are optional. ZATCA accepts Latin characters for names of people, places and products, so a
        buyer or a product named in English is already compliant &mdash; fill these in when the buyer or the product
        has an Arabic name of its own, and it will appear on the invoice and in the QR code.
    </p>
</div>

@if ($errors->any())
    <div class="mb-6 rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-950/40 p-4">
        <p class="text-sm font-semibold text-red-800 dark:text-red-200">
            {{ $errors->count() }} {{ Str::plural('problem', $errors->count()) }} to fix before this can be saved
        </p>
        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-red-700 dark:text-red-300">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ route('portal.invoices.store') }}" data-invoice-form>
    @csrf

    <div class="mb-6 rounded-lg bg-white dark:bg-gray-900 p-6 shadow">
        <h3 class="mb-4 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Document</h3>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <label for="invoice_number" class="block text-sm font-medium text-gray-700 dark:text-gray-200">Invoice number</label>
                <x-ui.input type="text" name="invoice_number" id="invoice_number" value="{{ old('invoice_number') }}" placeholder="Numbered when issued" />
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Leave empty to take the next number from your numbering when it is issued.</p>
            </div>

            <div>
                <label for="type" class="block text-sm font-medium text-gray-700 dark:text-gray-200">Type</label>
                <x-ui.select name="type" id="type">
                    <option value="simplified" @selected(old('type', 'simplified') === 'simplified')>Simplified (B2C)</option>
                    <option value="standard" @selected(old('type') === 'standard')>Standard (B2B)</option>
                </x-ui.select>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Standard needs the buyer's VAT number and address.</p>
            </div>

            <div>
                <label for="document_type" class="block text-sm font-medium text-gray-700 dark:text-gray-200">Document</label>
                <x-ui.select name="document_type" id="document_type">
                    <option value="invoice" @selected(old('document_type', 'invoice') === 'invoice')>Invoice</option>
                    <option value="credit_note" @selected(old('document_type') === 'credit_note')>Credit note</option>
                    <option value="debit_note" @selected(old('document_type') === 'debit_note')>Debit note</option>
                    <option value="prepayment" @selected(old('document_type') === 'prepayment')>Prepayment</option>
                </x-ui.select>
            </div>

            <div>
                <label for="issue_date" class="block text-sm font-medium text-gray-700 dark:text-gray-200">Issue date</label>
                <x-ui.input type="date" name="issue_date" id="issue_date"
                            value="{{ old('issue_date', now()->toDateString()) }}" required />
            </div>

            <div>
                <label for="supply_date" class="block text-sm font-medium text-gray-700 dark:text-gray-200">Supply date</label>
                <x-ui.input type="date" name="supply_date" id="supply_date" value="{{ old('supply_date') }}" />
            </div>

            <div>
                <label for="currency" class="block text-sm font-medium text-gray-700 dark:text-gray-200">Currency</label>
                <x-ui.input type="text" name="currency" id="currency" maxlength="3" value="{{ old('currency', 'SAR') }}" class="uppercase" />
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Anything but SAR needs an exchange rate.</p>
            </div>

            <div>
                <label for="exchange_rate" class="block text-sm font-medium text-gray-700 dark:text-gray-200">Exchange rate</label>
                <x-ui.input type="number" step="0.000001" min="0" name="exchange_rate" id="exchange_rate"
                       value="{{ old('exchange_rate') }}" />
            </div>

            <div>
                <label for="billing_ref" class="block text-sm font-medium text-gray-700 dark:text-gray-200">Billed document</label>
                <x-ui.input type="text" name="billing_ref" id="billing_ref" value="{{ old('billing_ref') }}" />
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Required for a credit or debit note.</p>
            </div>

            <div>
                <label for="adjustment_reason" class="block text-sm font-medium text-gray-700 dark:text-gray-200">Reason</label>
                <x-ui.input type="text" name="adjustment_reason" id="adjustment_reason" value="{{ old('adjustment_reason') }}" />
            </div>
        </div>
    </div>

    <div class="mb-6 rounded-lg bg-white dark:bg-gray-900 p-6 shadow">
        <h3 class="mb-4 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Buyer</h3>

        @if ($customers->isNotEmpty())
            {{-- Fills the fields below; it submits nothing itself, so what is
                 sent is what the fields say after any edit. --}}
            <div class="mb-4 max-w-md">
                <label for="customer_picker" class="block text-sm font-medium text-gray-700 dark:text-gray-200">Saved customer</label>
                <x-ui.select id="customer_picker" data-customer-picker>
                    <option value="">Choose to fill in the buyer...</option>
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
                <label for="buyer_name" class="block text-sm font-medium text-gray-700 dark:text-gray-200">Name</label>
                {{-- Not required here. ZATCA's BR-KSA-25 owes a name on a
                     simplified invoice only when a line is exempt as private
                     education or healthcare, and the server knows which lines
                     those are. Required in the browser, every cash sale made
                     the cashier invent a buyer. --}}
                <x-ui.input type="text" name="buyer_name" id="buyer_name" value="{{ old('buyer_name') }}" />
                <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">Leave empty for a walk-in customer on a simplified invoice.</span>
            </div>

            <div>
                <label for="buyer_name_ar" class="block text-sm font-medium text-gray-700 dark:text-gray-200">
                    Name in Arabic
                </label>
                <x-ui.input type="text" name="buyer_name_ar" id="buyer_name_ar" value="{{ old('buyer_name_ar') }}"
                       dir="rtl" lang="ar" />
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Optional &mdash; a Latin name is accepted for a proper name.</p>
            </div>

            <div>
                <label for="buyer_vat_number" class="block text-sm font-medium text-gray-700 dark:text-gray-200">VAT number</label>
                <x-ui.input type="text" name="buyer_vat_number" id="buyer_vat_number" value="{{ old('buyer_vat_number') }}" />
            </div>

            <div>
                <label for="buyer_address_street" class="block text-sm font-medium text-gray-700 dark:text-gray-200">Street</label>
                <x-ui.input type="text" name="buyer_address[street]" id="buyer_address_street"
                       value="{{ old('buyer_address.street') }}" />
            </div>

            <div>
                <label for="buyer_address_building_number" class="block text-sm font-medium text-gray-700 dark:text-gray-200">Building number</label>
                <x-ui.input type="text" name="buyer_address[building_number]" id="buyer_address_building_number"
                       value="{{ old('buyer_address.building_number') }}" />
            </div>

            <div>
                <label for="buyer_address_city" class="block text-sm font-medium text-gray-700 dark:text-gray-200">City</label>
                <x-ui.input type="text" name="buyer_address[city]" id="buyer_address_city"
                       value="{{ old('buyer_address.city') }}" />
            </div>

            <div>
                <label for="buyer_address_district" class="block text-sm font-medium text-gray-700 dark:text-gray-200">District</label>
                <x-ui.input type="text" name="buyer_address[district]" id="buyer_address_district"
                       value="{{ old('buyer_address.district') }}" />
            </div>

            <div>
                <label for="buyer_address_postal_code" class="block text-sm font-medium text-gray-700 dark:text-gray-200">Postal code</label>
                <x-ui.input type="text" name="buyer_address[postal_code]" id="buyer_address_postal_code"
                       value="{{ old('buyer_address.postal_code') }}" />
            </div>

            <div>
                <label for="buyer_address_country_code" class="block text-sm font-medium text-gray-700 dark:text-gray-200">Country</label>
                <x-ui.input type="text" name="buyer_address[country_code]" id="buyer_address_country_code" maxlength="2"
                       value="{{ old('buyer_address.country_code', 'SA') }}" class="uppercase" />
            </div>
        </div>

        <label class="mt-4 flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
            <input type="checkbox" name="save_customer" value="1" @checked(old('save_customer'))>
            Save this buyer to Customers
        </label>
    </div>

    <div class="mb-6 rounded-lg bg-white dark:bg-gray-900 p-6 shadow">
        <div class="mb-4 flex items-baseline justify-between">
            <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Lines</h3>
            <button type="button" data-add-line
                    class="rounded border border-teal-700 px-3 py-1 text-sm font-medium text-teal-700 dark:text-teal-300 transition-colors hover:bg-teal-50 dark:hover:bg-teal-950/40">
                Add a line
            </button>
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

    <div class="mb-6 rounded-lg bg-white dark:bg-gray-900 p-6 shadow">
        <h3 class="mb-4 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Totals and notes</h3>

        <div class="grid gap-4 sm:grid-cols-2">
            {{-- A select, not a checkbox: an unticked box sends nothing, which
                 would quietly fall back to the organization's own setting. --}}
            @php($inclusive = (string) old('prices_include_vat', $organization->prices_include_vat ? '1' : '0'))
            <div class="sm:col-span-2">
                <label for="prices_include_vat" class="block text-sm font-medium text-gray-700 dark:text-gray-200">Prices</label>
                <x-ui.select name="prices_include_vat" id="prices_include_vat">
                    <option value="0" @selected($inclusive === '0')>Exclude VAT - VAT is added to them</option>
                    <option value="1" @selected($inclusive === '1')>Include VAT - the customer pays exactly these</option>
                </x-ui.select>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Applies to the unit prices and the discount.</p>
            </div>

            <div>
                <label for="discount_amount" class="block text-sm font-medium text-gray-700 dark:text-gray-200">Document discount</label>
                <x-ui.input type="number" step="0.01" min="0" name="discount_amount" id="discount_amount"
                       value="{{ old('discount_amount') }}" />
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Apportioned across the tax categories, not taken off the total.</p>
            </div>

            <div>
                <label for="notes" class="block text-sm font-medium text-gray-700 dark:text-gray-200">Notes</label>
                <x-ui.textarea name="notes" id="notes" rows="3">{{ old('notes') }}</x-ui.textarea>
            </div>
        </div>
    </div>

    <div class="flex items-center gap-4">
        <x-ui.button>Save as draft</x-ui.button>
        <x-ui.button variant="quiet" :href="route('portal.invoices.index')">Cancel</x-ui.button>
    </div>
</form>
@endsection
