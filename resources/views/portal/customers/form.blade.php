@extends('layouts.portal')

@section('title', $customer->exists ? $customer->name : __('New customer'))

@section('content')
<div class="mb-6">
    <p class="text-sm"><a href="{{ route('portal.customers.index') }}" class="text-accent hover:underline"><span class="inline-block rtl:rotate-180">&larr;</span> {{ __('Customers') }}</a></p>
    <h2 class="mt-2 text-2xl font-semibold text-fg">{{ $customer->exists ? $customer->name : __('New customer') }}</h2>
    <p class="text-muted">{{ __('A standard (B2B) invoice needs the VAT number and the national address.') }}</p>
</div>

<form method="POST" action="{{ $customer->exists ? route('portal.customers.update', $customer->id) : route('portal.customers.store') }}"
      class="max-w-3xl space-y-6">
    @csrf
    <div class="rounded-card bg-surface border border-line shadow-card">
        <div class="grid grid-cols-1 gap-4 p-4 sm:grid-cols-2">
            <x-ui.field :label="__('Name')">
                <x-ui.input type="text" name="name" maxlength="255" required value="{{ old('name', $customer->name) }}" />
            </x-ui.field>
            <x-ui.field :label="__('Name in Arabic (optional)')">
                <x-ui.input type="text" name="name_ar" maxlength="255" dir="rtl" lang="ar" value="{{ old('name_ar', $customer->name_ar) }}" />
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
                <x-ui.input type="text" name="country_code" maxlength="2" required class="uppercase" value="{{ old('country_code', $customer->country_code ?? 'SA') }}" />
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
@endif
@endsection
