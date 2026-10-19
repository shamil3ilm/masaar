@extends('layouts.portal')

@section('title', $customer->exists ? $customer->name : __('New customer'))

@section('content')
<x-ui.page-header :title="$customer->exists ? $customer->name : __('New customer')" :description="__('A standard (B2B) invoice needs the VAT number and the national address.')">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('Customers'), 'href' => route('portal.customers.index')], ['label' => $customer->exists ? $customer->name : __('New customer')]]" />
    </x-slot:breadcrumb>
</x-ui.page-header>

<form method="POST" action="{{ $customer->exists ? route('portal.customers.update', $customer->id) : route('portal.customers.store') }}"
      class="max-w-3xl space-y-6">
    @csrf
    <div class="rounded-card bg-surface border border-line shadow-card">
        <div class="grid grid-cols-1 gap-4 p-4 sm:grid-cols-2">
            <x-ui.field :label="__('Name')">
                <x-ui.input type="text" name="name" maxlength="255" required value="{{ old('name', $customer->name) }}" />
            </x-ui.field>
            {{-- Required where the invoice must be in Arabic: an invoice naming
                 this record takes it as its own (LanguageRules). --}}
            <x-ui.field :label="($arabicRequired ?? false) ? __('Name in Arabic, as printed on the invoice') : __('Name in Arabic (optional)')">
                <x-ui.input type="text" name="name_ar" maxlength="255" dir="rtl" lang="ar" :required="$arabicRequired ?? false" value="{{ old('name_ar', $customer->name_ar) }}" />
            </x-ui.field>
            <x-ui.field :label="__('VAT number')">
                <x-ui.input type="text" name="vat_number" inputmode="numeric" maxlength="15" value="{{ old('vat_number', $customer->vat_number) }}" />
            </x-ui.field>
            <x-ui.field :label="__('Email (for sending invoices)')">
                <x-ui.input type="email" name="email" maxlength="255" value="{{ old('email', $customer->email) }}" />
            </x-ui.field>
            <x-ui.field :label="__('Phone')">
                <x-ui.input type="text" name="phone" maxlength="30" value="{{ old('phone', $customer->phone) }}" />
            </x-ui.field>
            <x-ui.field :label="__('Payment terms (filled into new invoices)')">
                <x-ui.input type="text" name="payment_terms" maxlength="1000" placeholder="{{ __('For example: 30 days net') }}" value="{{ old('payment_terms', $customer->payment_terms) }}" />
            </x-ui.field>
        </div>
    </div>

    <div class="rounded-card bg-surface border border-line shadow-card">
        <div class="border-b p-4">
            <h3 class="font-semibold text-fg">{{ __('National address') }}</h3>
        </div>
        <div class="grid grid-cols-1 gap-4 p-4 sm:grid-cols-2">
            <x-ui.field :label="__('Street')">
                <x-ui.input type="text" name="street" maxlength="255" value="{{ old('street', $customer->street) }}" />
            </x-ui.field>
            <x-ui.field :label="__('Building number')">
                <x-ui.input type="text" name="building_number" maxlength="20" value="{{ old('building_number', $customer->building_number) }}" />
            </x-ui.field>
            <x-ui.field :label="__('District')">
                <x-ui.input type="text" name="district" maxlength="100" value="{{ old('district', $customer->district) }}" />
            </x-ui.field>
            <x-ui.field :label="__('City')">
                <x-ui.input type="text" name="city" maxlength="100" value="{{ old('city', $customer->city) }}" />
            </x-ui.field>
            <x-ui.field :label="__('Postal code')">
                <x-ui.input type="text" name="postal_code" inputmode="numeric" maxlength="10" value="{{ old('postal_code', $customer->postal_code) }}" />
            </x-ui.field>
            <x-ui.field :label="__('Country')">
                <x-ui.country-select name="country_code" required :value="old('country_code', $customer->country_code ?? 'SA')" />
            </x-ui.field>
            {{-- The buyer details only the organization's country keeps (InvoiceRules::buyerView()). --}}
            @if ($buyerFields ?? null)
                @include($buyerFields, ['customer' => $customer])
            @endif
        </div>
    </div>

    <x-ui.button>{{ __('Save customer') }}</x-ui.button>
</form>

@if ($customer->exists)
    <form method="POST" action="{{ route('portal.customers.archive', $customer->id) }}" class="mt-6 max-w-3xl">
        @csrf
        @if ($customer->archived_at)
            <input type="hidden" name="restore" value="1">
            <x-ui.button variant="secondary">{{ __('Restore customer') }}</x-ui.button>
        @else
            <x-ui.button variant="secondary">{{ __('Archive customer') }}</x-ui.button>
            <p class="mt-1 text-xs text-muted">{{ __('Hidden from the invoice form. Invoices already written are not affected.') }}</p>
        @endif
    </form>

    {{-- Only while no invoice has used it; one that was used is archived (CatalogueRemoval). --}}
    @if ($removable)
        <form method="POST" action="{{ route('portal.customers.remove', $customer->id) }}" class="mt-4 max-w-3xl"
              data-confirm-tone="danger" data-confirm-action="{{ __('Remove customer') }}"
              data-confirm="{{ __('Remove customer :name?', ['name' => $customer->name]) }}"
              data-confirm-detail="{{ __('It leaves your lists and the invoice form. You can bring it back from the removed ones.') }}">
            @csrf
            <x-ui.button variant="danger">{{ __('Remove customer') }}</x-ui.button>
            <p class="mt-1 text-xs text-muted">{{ __('No invoice has used it, so it can be removed. Show the removed ones in the list to bring it back.') }}</p>
        </form>
    @endif
@endif
@endsection
