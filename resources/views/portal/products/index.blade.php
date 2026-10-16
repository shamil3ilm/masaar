@extends('layouts.portal')

@section('title', __('Products'))

@section('content')
<div class="mb-6 flex flex-wrap items-start justify-between gap-4">
    <div>
        <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ __('Products') }}</h2>
        <p class="text-gray-500 dark:text-gray-400">{{ __('What you sell, goods or services. Pick one on an invoice line and its description, price and VAT are filled in.') }}</p>
    </div>
    <x-ui.button :href="route('portal.products.create')">{{ __('New product') }}</x-ui.button>
</div>

<form method="GET" action="{{ route('portal.products.index') }}" class="mb-6 bg-white dark:bg-gray-900 rounded-lg shadow p-4 flex flex-wrap items-end gap-4">
    <div class="flex-1 min-w-48">
        <label for="q" class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">{{ __('Search') }}</label>
        <x-ui.input id="q" name="q" type="text" value="{{ $search }}" placeholder="{{ __('Name or code') }}" />
    </div>
    <div>
        <label for="archived" class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">{{ __('Showing') }}</label>
        <x-ui.select id="archived" name="archived">
            <option value="0">{{ __('Active') }}</option>
            <option value="1" @selected($archived)>{{ __('Archived') }}</option>
        </x-ui.select>
    </div>
    <div class="flex items-center gap-2">
        <x-ui.button variant="secondary">{{ __('Filter') }}</x-ui.button>
        <x-ui.button variant="quiet" :href="route('portal.products.index')">{{ __('Reset') }}</x-ui.button>
    </div>
</form>

<div class="bg-white dark:bg-gray-900 rounded-lg shadow overflow-hidden">
    <x-ui.table>
        <x-slot:head>
            <x-ui.th>{{ __('Name') }}</x-ui.th>
            <x-ui.th>{{ __('Code') }}</x-ui.th>
            <x-ui.th>{{ __('Unit') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Price') }}</x-ui.th>
            <x-ui.th align="right">{{ __('VAT') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Actions') }}</x-ui.th>
        </x-slot:head>
        @forelse ($products as $product)
        <tr class="hover:bg-gray-50 dark:hover:bg-gray-950">
            <td class="px-6 py-4 text-sm">
                <div class="font-medium text-gray-900 dark:text-gray-100">{{ $product->name }}</div>
                @if ($product->name_ar)
                    <div class="text-gray-500 dark:text-gray-400" dir="rtl" lang="ar">{{ $product->name_ar }}</div>
                @endif
            </td>
            <td class="px-6 py-4 text-sm font-mono text-gray-600 dark:text-gray-300">{{ $product->sku ?? '-' }}</td>
            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ __(\App\Domains\Invoice\Support\UnitCode::label($product->unit_code)) }}</td>
            <td class="px-6 py-4 text-end text-sm tabular-nums text-gray-800 dark:text-gray-100">{{ number_format((float) $product->unit_price, 2) }}</td>
            <td class="px-6 py-4 text-end text-sm tabular-nums text-gray-600 dark:text-gray-300">{{ (float) $product->tax_rate }}%</td>
            <td class="px-6 py-4 text-end text-sm">
                <a href="{{ route('portal.products.edit', $product->id) }}" class="text-teal-700 dark:text-teal-300 hover:underline">{{ __('Edit') }}</a>
            </td>
        </tr>
        @empty
        <x-ui.empty :columns="6">{{ $archived ? __('No archived products') : __('No products yet') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

<div class="mt-4">
    {{ $products->links() }}
</div>
@endsection
