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

<form method="GET" action="{{ route('portal.customers.index') }}" class="mb-6 bg-surface rounded-card border border-line shadow-card p-4 flex flex-wrap items-end gap-4">
    <div class="flex-1 min-w-48">
        <label for="q" class="block text-xs font-medium text-muted uppercase mb-1">{{ __('Search') }}</label>
        <x-ui.input id="q" name="q" type="text" value="{{ $search }}" placeholder="{{ __('Name or VAT number') }}" />
    </div>
    <div>
        <label for="archived" class="block text-xs font-medium text-muted uppercase mb-1">{{ __('Showing') }}</label>
        <x-ui.select id="archived" name="archived">
            <option value="0">{{ __('Active') }}</option>
            <option value="1" @selected($archived)>{{ __('Archived') }}</option>
        </x-ui.select>
    </div>
    <div class="flex items-center gap-2">
        <x-ui.button variant="secondary">{{ __('Filter') }}</x-ui.button>
        <x-ui.button variant="ghost" :href="route('portal.customers.index')">{{ __('Reset') }}</x-ui.button>
    </div>
</form>

<div class="bg-surface rounded-card border border-line shadow-card overflow-hidden">
    <x-ui.table>
        <x-slot:head>
            <x-ui.th>{{ __('Name') }}</x-ui.th>
            <x-ui.th>{{ __('VAT number') }}</x-ui.th>
            <x-ui.th>{{ __('City') }}</x-ui.th>
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
        <x-ui.empty :columns="5">{{ $archived ? __('No archived customers') : __('No customers yet. Add one here, or tick "Save this buyer" on an invoice.') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

<div class="mt-4">
    {{ $customers->links() }}
</div>
@endsection
