@extends('layouts.portal')

@section('title', __('Products'))

@section('content')
<div class="mb-6 flex flex-wrap items-start justify-between gap-4">
    <div>
        <h1 class="text-2xl font-semibold tracking-tight text-fg">{{ __('Products') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-muted">{{ __('What you sell, goods or services. Pick one on an invoice line and its description, price and VAT are filled in.') }}</p>
    </div>
    <x-ui.button :href="route('portal.products.create')">{{ __('New product') }}</x-ui.button>
</div>

<x-ui.list-filters :list="$list" />

<div class="bg-surface rounded-card border border-line shadow-card overflow-hidden">
    <x-ui.table :primary="[0, 3, 1]">
        <x-slot:head>
            <x-ui.th :list="$list" sort="name">{{ __('Name') }}</x-ui.th>
            <x-ui.th>{{ __('Code') }}</x-ui.th>
            <x-ui.th>{{ __('Unit') }}</x-ui.th>
            <x-ui.th :list="$list" sort="price" align="right">{{ __('Price') }}</x-ui.th>
            <x-ui.th align="right">{{ __('VAT') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Actions') }}</x-ui.th>
        </x-slot:head>
        @forelse ($products as $product)
        <tr class="hover:bg-sunken">
            <td class="px-6 py-4 text-sm">
                <div class="font-medium text-fg">{{ $product->name }}</div>
                @if ($product->name_ar)
                    <div class="text-muted" dir="rtl" lang="ar">{{ $product->name_ar }}</div>
                @endif
            </td>
            <td class="px-6 py-4 text-sm font-mono text-muted">{{ $product->sku ?? '-' }}</td>
            <td class="px-6 py-4 text-sm text-muted">{{ __(\App\Domains\Invoice\Support\UnitCode::label($product->unit_code)) }}</td>
            <td class="px-6 py-4 text-end text-sm tabular-nums text-fg">{{ number_format((float) $product->unit_price, 2) }}</td>
            <td class="px-6 py-4 text-end text-sm tabular-nums text-muted">{{ (float) $product->tax_rate }}%</td>
            <td class="px-6 py-4 text-end text-sm">
                @if ($removed)
                    <form method="POST" action="{{ route('portal.products.restore-removed', $product->id) }}">
                        @csrf
                        <x-ui.button size="sm" variant="secondary">{{ __('Restore') }}</x-ui.button>
                    </form>
                @else
                    <a href="{{ route('portal.products.edit', $product->id) }}" class="text-accent hover:underline">{{ __('Edit') }}</a>
                @endif
            </td>
        </tr>
        @empty
        <x-ui.empty :columns="6">{{ $list->filtered() ? __('No product matches that filter.') : __('No products yet') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

<div class="mt-4">
    {{ $products->links() }}
</div>
@endsection
