@extends('layouts.portal')

@section('title', __('Sales reports'))

{{--
    The period's sales grouped one way (SalesReports), in the tax currency.

    @param array<string, mixed> $report
    @param string $group
    @param array<string, string> $groups
    @param string $sort
--}}

@section('content')
@php($query = ['by' => $group, 'sort' => $sort, 'from' => $from->toDateString(), 'to' => $to->toDateString()])
<x-ui.page-header :title="__('Sales reports')" :description="__('From :from to :to, in :currency. Credit notes are taken off.', ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'currency' => $report['currency']])">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('Books'), 'href' => route('portal.books.index')], ['label' => __('Sales reports')]]" />
    </x-slot:breadcrumb>
    <x-slot:actions>
        <x-ui.button variant="secondary" :href="route('portal.books.sales.csv', $query)">{{ __('Download CSV') }}</x-ui.button>
    </x-slot:actions>
</x-ui.page-header>

<x-ui.section :title="__('Filters')" collapse class="mb-6">
    <form method="GET" action="{{ route('portal.books.sales') }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <x-ui.field :label="__('Group by')">
            <x-ui.select name="by">
                @foreach ($groups as $value => $label)
                    <option value="{{ $value }}" @selected($group === $value)>{{ $label }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>
        <x-ui.field :label="__('From')"><x-ui.date-input name="from" value="{{ $from->toDateString() }}" /></x-ui.field>
        <x-ui.field :label="__('To')"><x-ui.date-input name="to" value="{{ $to->toDateString() }}" /></x-ui.field>
        <x-ui.field :label="__('Sort by')">
            <x-ui.select name="sort">
                <option value="amount" @selected($sort === 'amount')>{{ __('Largest first') }}</option>
                <option value="name" @selected($sort === 'name')>{{ __('Name') }}</option>
            </x-ui.select>
        </x-ui.field>
        <div class="flex items-end"><x-ui.button>{{ __('Apply filters') }}</x-ui.button></div>
    </form>
</x-ui.section>

@if ($report['missing_rate'] !== [])
    <x-ui.alert tone="warning" class="mb-4">{{ __('Left out, with no exchange rate: :numbers.', ['numbers' => implode(', ', $report['missing_rate'])]) }}</x-ui.alert>
@endif

<x-ui.card class="overflow-hidden" flush>
    <x-ui.table :primary="[0, 5]">
        <x-slot:head>
            <x-ui.th>{{ $groups[$group] }}</x-ui.th>
            <x-ui.th align="right">{{ __('Documents') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Quantity') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Net') }}</x-ui.th>
            <x-ui.th align="right">{{ __('VAT') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Total') }}</x-ui.th>
        </x-slot:head>
        @forelse ($report['rows'] as $row)
            <tr>
                <td class="px-6 py-3 text-sm text-fg">{{ $row['label'] }}</td>
                <td class="px-6 py-3 text-end text-sm tabular-nums">{{ $row['documents'] }}</td>
                <td class="px-6 py-3 text-end text-sm tabular-nums" dir="ltr">{{ $row['quantity'] ?? '-' }}</td>
                <td class="px-6 py-3 text-end text-sm"><x-ui.amount :value="$row['base']" /></td>
                <td class="px-6 py-3 text-end text-sm"><x-ui.amount :value="$row['vat']" /></td>
                <td class="px-6 py-3 text-end text-sm font-semibold"><x-ui.amount :value="$row['total']" /></td>
            </tr>
        @empty
            <x-ui.empty :columns="6">{{ __('No sales in this period.') }}</x-ui.empty>
        @endforelse
        @if ($report['rows'] !== [])
            <tr class="font-semibold">
                <td class="px-6 py-3 text-sm" colspan="3">{{ __('Total') }}</td>
                <td class="px-6 py-3 text-end text-sm"><x-ui.amount :value="$report['base']" /></td>
                <td class="px-6 py-3 text-end text-sm"><x-ui.amount :value="$report['vat']" /></td>
                <td class="px-6 py-3 text-end text-sm"><x-ui.amount :value="$report['total']" :currency="$report['currency']" /></td>
            </tr>
        @endif
    </x-ui.table>
</x-ui.card>

@if ($group === 'item')
    <p class="mt-3 text-sm text-muted">{{ __('An item\'s figures are its lines before any discount on the whole document.') }}</p>
@endif
@endsection
