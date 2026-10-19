@extends('layouts.portal')

@section('title', __('Customers'))

@section('content')
<x-ui.page-header :title="__('Customers')" :description="__('The people and companies you invoice. Pick one on a new invoice and its details are filled in.')">
    <x-slot:actions>
        <x-ui.button :href="route('portal.customers.create')">{{ __('New customer') }}</x-ui.button>
    </x-slot:actions>
</x-ui.page-header>

<x-ui.list-filters :list="$list" />

<div class="bg-surface rounded-card border border-line shadow-card overflow-hidden">
    <x-ui.table :primary="[0, 1]">
        <x-slot:head>
            <x-ui.th :list="$list" sort="name">{{ __('Name') }}</x-ui.th>
            <x-ui.th>{{ __('VAT number') }}</x-ui.th>
            <x-ui.th :list="$list" sort="city">{{ __('City') }}</x-ui.th>
            <x-ui.th>{{ __('Email') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Actions') }}</x-ui.th>
        </x-slot:head>
        @forelse ($customers as $customer)
        <tr class="hover:bg-sunken">
            <td class="px-6 py-4 text-sm">
                <div class="font-medium text-fg">{{ $customer->name }}</div>
                @if ($customer->name_ar)
                    <div class="text-muted" dir="rtl" lang="ar">{{ $customer->name_ar }}</div>
                @endif
            </td>
            <td class="px-6 py-4 text-sm font-mono text-muted">{{ $customer->vat_number ?? '-' }}</td>
            <td class="px-6 py-4 text-sm text-muted">{{ $customer->city ?? '-' }}</td>
            <td class="px-6 py-4 text-sm text-muted break-all">{{ $customer->email ?? '-' }}</td>
            <td class="px-6 py-4 text-end text-sm">
                @if ($removed)
                    <form method="POST" action="{{ route('portal.customers.restore-removed', $customer->id) }}">
                        @csrf
                        <x-ui.button size="sm" variant="secondary">{{ __('Restore') }}</x-ui.button>
                    </form>
                @else
                    <a href="{{ route('portal.customers.edit', $customer->id) }}" class="text-accent hover:underline">{{ __('Edit') }}</a>
                @endif
            </td>
        </tr>
        @empty
        <x-ui.empty :columns="5">{{ $list->filtered() ? __('No customer matches that filter.') : __('No customers yet. Add one here, or tick "Save this buyer" on an invoice.') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

{{ $customers->links() }}
@endsection
