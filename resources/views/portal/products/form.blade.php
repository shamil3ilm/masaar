@extends('layouts.portal')

@section('title', $product->exists ? $product->name : 'New product')

@section('content')
<div class="mb-6">
    <p class="text-sm"><a href="{{ route('portal.products.index') }}" class="text-teal-700 dark:text-teal-300 hover:underline">&larr; Products</a></p>
    <h2 class="mt-2 text-2xl font-bold text-gray-800 dark:text-gray-100">{{ $product->exists ? $product->name : 'New product' }}</h2>
</div>

<form method="POST" action="{{ $product->exists ? route('portal.products.update', $product->id) : route('portal.products.store') }}"
      class="max-w-3xl space-y-6">
    @csrf
    <div class="rounded-lg bg-white dark:bg-gray-900 shadow">
        <div class="grid grid-cols-1 gap-4 p-4 sm:grid-cols-2">
            <x-ui.field label="Name, as printed on the invoice">
                <x-ui.input type="text" name="name" maxlength="255" required value="{{ old('name', $product->name) }}" />
            </x-ui.field>
            <x-ui.field label="Name in Arabic (optional)">
                <x-ui.input type="text" name="name_ar" maxlength="255" dir="rtl" lang="ar" value="{{ old('name_ar', $product->name_ar) }}" />
            </x-ui.field>
            <x-ui.field label="Code or SKU (optional)">
                <x-ui.input type="text" name="sku" maxlength="64" value="{{ old('sku', $product->sku) }}" />
            </x-ui.field>
            <x-ui.field label="Unit">
                @php($selected = \App\Domains\Invoice\Support\UnitCode::normalise(old('unit_code', $product->unit_code)))
                <x-ui.select name="unit_code">
                    @foreach (\App\Domains\Invoice\Support\UnitCode::GROUPS as $group => $codes)
                        <optgroup label="{{ $group }}">
                            @foreach ($codes as $code => $label)
                                <option value="{{ $code }}" @selected($selected === $code)>{{ $label }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <x-ui.field label="Unit price">
                <x-ui.input type="number" step="0.01" min="0" name="unit_price" required value="{{ old('unit_price', $product->unit_price) }}" />
            </x-ui.field>
            <x-ui.field label="VAT %">
                <x-ui.input type="number" step="0.01" min="0" max="100" name="tax_rate" required value="{{ old('tax_rate', $product->tax_rate === null ? '' : (float) $product->tax_rate) }}" />
            </x-ui.field>
        </div>
    </div>

    <x-ui.button>Save product</x-ui.button>
</form>

@if ($product->exists)
    <form method="POST" action="{{ route('portal.products.archive', $product->id) }}" class="mt-6 max-w-3xl">
        @csrf
        @if ($product->archived_at)
            <input type="hidden" name="restore" value="1">
            <x-ui.button variant="outline">Restore product</x-ui.button>
        @else
            <x-ui.button variant="outline">Archive product</x-ui.button>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Hidden from the invoice form. Invoices already written are not affected.</p>
        @endif
    </form>
@endif
@endsection
