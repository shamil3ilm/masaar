@extends('layouts.admin')

@section('title', __('Suppliers'))

@section('content')
{{--
    Finance › Suppliers: who the platform pays - hosting, its e-invoicing
    service provider, its card gateway, subscriptions. Archived and removed
    ones are behind the Showing filter.
--}}
<x-ui.page-header :eyebrow="__('Finance')" :title="__('Suppliers')"
    :description="__('Who the platform pays, with their tax numbers, bank details and terms.')">
    @admincan('billing.manage')
    <x-slot:actions>
        <x-ui.button :href="route('admin.finance.suppliers.create')">{{ __('Add a supplier') }}</x-ui.button>
    </x-slot:actions>
    @endadmincan
</x-ui.page-header>

<x-ui.list-filters :list="$list" />

<div class="overflow-hidden rounded-card border border-line bg-surface shadow-card">
    <x-ui.table :primary="[0, 1, 3]">
        <x-slot:head>
            <x-ui.th :list="$list" sort="name">{{ __('Name') }}</x-ui.th>
            <x-ui.th>{{ __('Category') }}</x-ui.th>
            <x-ui.th>{{ __('Country') }}</x-ui.th>
            <x-ui.th>{{ __('Currency') }}</x-ui.th>
            <x-ui.th>{{ __('VAT number') }}</x-ui.th>
            <x-ui.th>{{ __('Terms') }}</x-ui.th>
        </x-slot:head>
        @forelse ($suppliers as $supplier)
            <tr class="hover:bg-sunken">
                <td class="px-6 py-4 text-sm">
                    <a href="{{ route('admin.finance.suppliers.show', $supplier->id) }}" class="font-semibold text-accent hover:underline">{{ $supplier->name }}</a>
                    @if ($supplier->trashed())
                        <x-ui.badge tone="neutral" :label="__('Removed')" />
                    @elseif ($supplier->archived())
                        <x-ui.badge tone="neutral" :label="__('Archived')" />
                    @endif
                </td>
                <td class="px-6 py-4 text-sm text-muted">{{ $categories[(string) $supplier->category] ?? $supplier->category }}</td>
                <td class="px-6 py-4 text-sm text-muted">{{ \App\Support\CountryNames::name($supplier->country) }}</td>
                <td class="px-6 py-4 text-sm text-muted">{{ $supplier->currency }}</td>
                <td class="px-6 py-4 text-sm text-muted" dir="ltr">{{ $supplier->vat_number ?? '-' }}</td>
                <td class="px-6 py-4 text-sm text-muted">{{ __(':count days', ['count' => $supplier->payment_terms_days]) }}</td>
            </tr>
        @empty
            <x-ui.empty :columns="6">{{ __('No supplier matches.') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

{{ $suppliers->links() }}
@endsection
