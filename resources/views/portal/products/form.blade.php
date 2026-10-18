@extends('layouts.portal')

@section('title', $product->exists ? $product->name : __('New product'))

@section('content')
<div class="mb-6">
    <p class="text-sm"><a href="{{ route('portal.products.index') }}" class="text-accent hover:underline"><span class="inline-block rtl:rotate-180">&larr;</span> {{ __('Products') }}</a></p>
    <h2 class="mt-2 text-2xl font-semibold text-fg">{{ $product->exists ? $product->name : __('New product') }}</h2>
</div>

<form method="POST" action="{{ $product->exists ? route('portal.products.update', $product->id) : route('portal.products.store') }}"
      class="max-w-3xl space-y-6">
    @csrf
    <div class="rounded-card bg-surface border border-line shadow-card">
        <div class="grid grid-cols-1 gap-4 p-4 sm:grid-cols-2">
            <x-ui.field :label="__('Name, as printed on the invoice')">
                <x-ui.input type="text" name="name" maxlength="255" required value="{{ old('name', $product->name) }}" />
            </x-ui.field>
            {{-- Required where the invoice must be in Arabic: an invoice naming
                 this record takes it as its own (LanguageRules). --}}
            <x-ui.field :label="($arabicRequired ?? false) ? __('Name in Arabic, as printed on the invoice') : __('Name in Arabic (optional)')">
                <x-ui.input type="text" name="name_ar" maxlength="255" dir="rtl" lang="ar" :required="$arabicRequired ?? false" value="{{ old('name_ar', $product->name_ar) }}" />
            </x-ui.field>
            <x-ui.field :label="__('Code or SKU (optional)')">
                <x-ui.input type="text" name="sku" maxlength="64" value="{{ old('sku', $product->sku) }}" />
            </x-ui.field>
            <x-ui.field :label="__('Unit')">
                @php($selected = \App\Domains\Invoice\Support\UnitCode::normalise(old('unit_code', $product->unit_code)))
                <x-ui.select name="unit_code">
                    @foreach (\App\Domains\Invoice\Support\UnitCode::GROUPS as $group => $codes)
                        <optgroup label="{{ __($group) }}">
                            @foreach ($codes as $code => $label)
                                <option value="{{ $code }}" @selected($selected === $code)>{{ __($label) }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <x-ui.field :label="__('Unit price')">
                <x-ui.input type="number" step="0.01" min="0" name="unit_price" required value="{{ old('unit_price', $product->unit_price) }}" />
            </x-ui.field>
            <x-ui.field :label="__('VAT %')">
                <x-ui.input type="number" step="0.01" min="0" max="100" name="tax_rate" required value="{{ old('tax_rate', $product->tax_rate === null ? '' : (float) $product->tax_rate) }}" />
            </x-ui.field>
        </div>
    </div>

    <x-ui.button>{{ __('Save product') }}</x-ui.button>
</form>

@if ($product->exists)
    <form method="POST" action="{{ route('portal.products.archive', $product->id) }}" class="mt-6 max-w-3xl">
        @csrf
        @if ($product->archived_at)
            <input type="hidden" name="restore" value="1">
            <x-ui.button variant="secondary">{{ __('Restore product') }}</x-ui.button>
        @else
            <x-ui.button variant="secondary">{{ __('Archive product') }}</x-ui.button>
            <p class="mt-1 text-xs text-muted">{{ __('Hidden from the invoice form. Invoices already written are not affected.') }}</p>
        @endif
    </form>
@endif
@endsection
