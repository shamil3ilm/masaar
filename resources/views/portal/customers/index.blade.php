@extends('layouts.portal')

@section('title', __('Customers'))

@section('content')
<div class="mb-6 flex flex-wrap items-start justify-between gap-4">
    <div>
        <h1 class="text-2xl font-semibold tracking-tight text-fg">{{ __('Customers') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-muted">{{ __('The people and companies you invoice. Pick one on a new invoice and its details are filled in.') }}</p>
    </div>
    <x-ui.button :href="route('portal.customers.create')">{{ __('New customer') }}</x-ui.button>
</div>

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
                <a href="{{ route('portal.customers.edit', $customer->id) }}" class="text-accent hover:underline">{{ __('Edit') }}</a>
            </td>
        </tr>
        @empty
        <x-ui.empty :columns="5">{{ $list->filtered() ? __('No customer matches that filter.') : __('No customers yet. Add one here, or tick "Save this buyer" on an invoice.') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

{{ $customers->links() }}
@endsection
