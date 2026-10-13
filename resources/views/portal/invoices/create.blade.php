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
    <h2 class="text-2xl font-bold text-gray-800">New invoice</h2>
    <p class="text-gray-500">
        Saved as a draft. It has no counter, no hash and no QR until it is issued, and a draft can be changed.
    </p>
    <p class="mt-2 text-sm text-gray-500">
        The Arabic fields are optional. ZATCA accepts Latin characters for names of people, places and products, so a
        buyer or a product named in English is already compliant &mdash; fill these in when the buyer or the product
        has an Arabic name of its own, and it will appear on the invoice and in the QR code.
    </p>
</div>

@if ($errors->any())
    <div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4">
        <p class="text-sm font-semibold text-red-800">
            {{ $errors->count() }} {{ Str::plural('problem', $errors->count()) }} to fix before this can be saved
        </p>
        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-red-700">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ route('portal.invoices.store') }}" data-invoice-form>
    @csrf

    <div class="mb-6 rounded-lg bg-white p-6 shadow">
        <h3 class="mb-4 text-xs font-semibold uppercase tracking-wider text-gray-500">Document</h3>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <label for="invoice_number" class="block text-sm font-medium text-gray-700">Invoice number</label>
                <input type="text" name="invoice_number" id="invoice_number" value="{{ old('invoice_number') }}"
                       class="mt-1 w-full rounded border-gray-300 text-sm shadow-sm" required>
            </div>

            <div>
                <label for="type" class="block text-sm font-medium text-gray-700">Type</label>
                <select name="type" id="type" class="mt-1 w-full rounded border-gray-300 text-sm shadow-sm">
                    <option value="simplified" @selected(old('type', 'simplified') === 'simplified')>Simplified (B2C)</option>
                    <option value="standard" @selected(old('type') === 'standard')>Standard (B2B)</option>
                </select>
                <p class="mt-1 text-xs text-gray-500">Standard needs the buyer's VAT number and address.</p>
            </div>

            <div>
                <label for="document_type" class="block text-sm font-medium text-gray-700">Document</label>
                <select name="document_type" id="document_type" class="mt-1 w-full rounded border-gray-300 text-sm shadow-sm">
                    <option value="invoice" @selected(old('document_type', 'invoice') === 'invoice')>Invoice</option>
                    <option value="credit_note" @selected(old('document_type') === 'credit_note')>Credit note</option>
                    <option value="debit_note" @selected(old('document_type') === 'debit_note')>Debit note</option>
                    <option value="prepayment" @selected(old('document_type') === 'prepayment')>Prepayment</option>
                </select>
            </div>

            <div>
                <label for="issue_date" class="block text-sm font-medium text-gray-700">Issue date</label>
                <input type="date" name="issue_date" id="issue_date"
                       value="{{ old('issue_date', now()->toDateString()) }}"
                       class="mt-1 w-full rounded border-gray-300 text-sm shadow-sm" required>
            </div>

            <div>
                <label for="supply_date" class="block text-sm font-medium text-gray-700">Supply date</label>
                <input type="date" name="supply_date" id="supply_date" value="{{ old('supply_date') }}"
                       class="mt-1 w-full rounded border-gray-300 text-sm shadow-sm">
            </div>

            <div>
                <label for="currency" class="block text-sm font-medium text-gray-700">Currency</label>
                <input type="text" name="currency" id="currency" maxlength="3" value="{{ old('currency', 'SAR') }}"
                       class="mt-1 w-full rounded border-gray-300 text-sm uppercase shadow-sm">
                <p class="mt-1 text-xs text-gray-500">Anything but SAR needs an exchange rate.</p>
            </div>

            <div>
                <label for="exchange_rate" class="block text-sm font-medium text-gray-700">Exchange rate</label>
                <input type="number" step="0.000001" min="0" name="exchange_rate" id="exchange_rate"
                       value="{{ old('exchange_rate') }}"
                       class="mt-1 w-full rounded border-gray-300 text-sm shadow-sm">
            </div>

            <div>
                <label for="billing_ref" class="block text-sm font-medium text-gray-700">Billed document</label>
                <input type="text" name="billing_ref" id="billing_ref" value="{{ old('billing_ref') }}"
                       class="mt-1 w-full rounded border-gray-300 text-sm shadow-sm">
                <p class="mt-1 text-xs text-gray-500">Required for a credit or debit note.</p>
            </div>

            <div>
                <label for="adjustment_reason" class="block text-sm font-medium text-gray-700">Reason</label>
                <input type="text" name="adjustment_reason" id="adjustment_reason" value="{{ old('adjustment_reason') }}"
                       class="mt-1 w-full rounded border-gray-300 text-sm shadow-sm">
            </div>
        </div>
    </div>

    <div class="mb-6 rounded-lg bg-white p-6 shadow">
        <h3 class="mb-4 text-xs font-semibold uppercase tracking-wider text-gray-500">Buyer</h3>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <label for="buyer_name" class="block text-sm font-medium text-gray-700">Name</label>
                <input type="text" name="buyer_name" id="buyer_name" value="{{ old('buyer_name') }}"
                       class="mt-1 w-full rounded border-gray-300 text-sm shadow-sm" required>
            </div>

            <div>
                <label for="buyer_name_ar" class="block text-sm font-medium text-gray-700">
                    Name in Arabic
                </label>
                <input type="text" name="buyer_name_ar" id="buyer_name_ar" value="{{ old('buyer_name_ar') }}"
                       dir="rtl" lang="ar"
                       class="mt-1 w-full rounded border-gray-300 text-sm shadow-sm">
                <p class="mt-1 text-xs text-gray-500">Optional &mdash; a Latin name is accepted for a proper name.</p>
            </div>

            <div>
                <label for="buyer_vat_number" class="block text-sm font-medium text-gray-700">VAT number</label>
                <input type="text" name="buyer_vat_number" id="buyer_vat_number" value="{{ old('buyer_vat_number') }}"
                       class="mt-1 w-full rounded border-gray-300 text-sm shadow-sm">
            </div>

            <div>
                <label for="buyer_address_street" class="block text-sm font-medium text-gray-700">Street</label>
                <input type="text" name="buyer_address[street]" id="buyer_address_street"
                       value="{{ old('buyer_address.street') }}"
                       class="mt-1 w-full rounded border-gray-300 text-sm shadow-sm">
            </div>

            <div>
                <label for="buyer_address_building_number" class="block text-sm font-medium text-gray-700">Building number</label>
                <input type="text" name="buyer_address[building_number]" id="buyer_address_building_number"
                       value="{{ old('buyer_address.building_number') }}"
                       class="mt-1 w-full rounded border-gray-300 text-sm shadow-sm">
            </div>

            <div>
                <label for="buyer_address_city" class="block text-sm font-medium text-gray-700">City</label>
                <input type="text" name="buyer_address[city]" id="buyer_address_city"
                       value="{{ old('buyer_address.city') }}"
                       class="mt-1 w-full rounded border-gray-300 text-sm shadow-sm">
            </div>

            <div>
                <label for="buyer_address_district" class="block text-sm font-medium text-gray-700">District</label>
                <input type="text" name="buyer_address[district]" id="buyer_address_district"
                       value="{{ old('buyer_address.district') }}"
                       class="mt-1 w-full rounded border-gray-300 text-sm shadow-sm">
            </div>

            <div>
                <label for="buyer_address_postal_code" class="block text-sm font-medium text-gray-700">Postal code</label>
                <input type="text" name="buyer_address[postal_code]" id="buyer_address_postal_code"
                       value="{{ old('buyer_address.postal_code') }}"
                       class="mt-1 w-full rounded border-gray-300 text-sm shadow-sm">
            </div>

            <div>
                <label for="buyer_address_country_code" class="block text-sm font-medium text-gray-700">Country</label>
                <input type="text" name="buyer_address[country_code]" id="buyer_address_country_code" maxlength="2"
                       value="{{ old('buyer_address.country_code', 'SA') }}"
                       class="mt-1 w-full rounded border-gray-300 text-sm uppercase shadow-sm">
            </div>
        </div>
    </div>

    <div class="mb-6 rounded-lg bg-white p-6 shadow">
        <div class="mb-4 flex items-baseline justify-between">
            <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500">Lines</h3>
            <button type="button" data-add-line
                    class="rounded border border-teal-700 px-3 py-1 text-sm font-medium text-teal-700 transition-colors hover:bg-teal-50">
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

    <div class="mb-6 rounded-lg bg-white p-6 shadow">
        <h3 class="mb-4 text-xs font-semibold uppercase tracking-wider text-gray-500">Totals and notes</h3>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="discount_amount" class="block text-sm font-medium text-gray-700">Document discount</label>
                <input type="number" step="0.01" min="0" name="discount_amount" id="discount_amount"
                       value="{{ old('discount_amount') }}"
                       class="mt-1 w-full rounded border-gray-300 text-sm shadow-sm">
                <p class="mt-1 text-xs text-gray-500">Apportioned across the tax categories, not taken off the total.</p>
            </div>

            <div>
                <label for="notes" class="block text-sm font-medium text-gray-700">Notes</label>
                <textarea name="notes" id="notes" rows="3"
                          class="mt-1 w-full rounded border-gray-300 text-sm shadow-sm">{{ old('notes') }}</textarea>
            </div>
        </div>
    </div>

    <div class="flex items-center gap-4">
        <x-ui.button>Save as draft</x-ui.button>
        <x-ui.button variant="quiet" :href="route('portal.invoices.index')">Cancel</x-ui.button>
    </div>
</form>
@endsection
