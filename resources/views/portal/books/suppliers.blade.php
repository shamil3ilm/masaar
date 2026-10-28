@extends('layouts.portal')

@section('title', __('Suppliers'))

{{--
    The organization's suppliers, for its purchases, and adding one.

    @param \Illuminate\Support\Collection $suppliers
    @param bool $records
--}}

@section('content')
<x-ui.page-header :title="__('Suppliers')" :description="__('Who you buy from. A supplier abroad is one whose services are reverse charge.')">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('Books'), 'href' => route('portal.books.index')], ['label' => __('Suppliers')]]" />
    </x-slot:breadcrumb>
</x-ui.page-header>

<x-ui.card class="mb-6 overflow-hidden" flush>
    <x-ui.table :primary="[0, 1]">
        <x-slot:head>
            <x-ui.th>{{ __('Name') }}</x-ui.th>
            <x-ui.th>{{ __('VAT number') }}</x-ui.th>
            <x-ui.th>{{ __('Country') }}</x-ui.th>
            <x-ui.th>{{ __('City') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Actions') }}</x-ui.th>
        </x-slot:head>
        @forelse ($suppliers as $supplier)
            <tr>
                <td class="px-6 py-3 text-sm font-medium text-fg">{{ $supplier->name }}</td>
                <td class="px-6 py-3 text-sm text-muted" dir="ltr">{{ $supplier->vat_number ?? '-' }}</td>
                <td class="px-6 py-3 text-sm text-muted">{{ $supplier->country_code ? \App\Support\CountryNames::name($supplier->country_code) : '-' }}</td>
                <td class="px-6 py-3 text-sm text-muted">{{ $supplier->city ?? '-' }}</td>
                <td class="px-6 py-3 text-end text-sm">
                    <a href="{{ route('portal.books.suppliers.edit', $supplier->id) }}" class="text-accent hover:underline">{{ $records ? __('Edit') : __('View') }}</a>
                </td>
            </tr>
        @empty
            <x-ui.empty :columns="5">{{ __('No suppliers yet. Add one below, or import purchases and they are added for you.') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</x-ui.card>

@if ($records)
    <x-ui.section :title="__('Add a supplier')" collapse>
        @include('portal.books.partials.supplier-fields', ['supplier' => new \App\Domains\Books\Models\Supplier, 'action' => route('portal.books.suppliers.store')])
    </x-ui.section>
@endif
@endsection
