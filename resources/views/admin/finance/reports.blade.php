@extends('layouts.admin')

@section('title', __('Finance reports'))

@section('content')
@php($period = ['from' => $from->toDateString(), 'to' => $to->toDateString()])
<x-ui.page-header :title="__('Finance reports')" :description="__('What the platform invoiced its customers, what it collected and how, the VAT on its own invoices, and what is owed by age. Every amount in its own currency; VAT also in :currency.', ['currency' => $vat['tax_currency']])" />

<form method="GET" action="{{ route('admin.finance.reports') }}" class="mb-6 flex flex-wrap items-end gap-4">
    <x-ui.field :label="__('From')">
        <x-ui.date-input name="from" value="{{ $period['from'] }}" />
    </x-ui.field>
    <x-ui.field :label="__('To')">
        <x-ui.date-input name="to" value="{{ $period['to'] }}" />
    </x-ui.field>
    <x-ui.button variant="secondary">{{ __('Show') }}</x-ui.button>
</form>

@if ($unrecorded !== [])
    <x-ui.alert tone="danger" class="mb-6" :title="__('Invoices issued with no receivable')">
        {{ __('These tax documents were issued, but a step after issuing failed, so the books do not show what is owed on them: :invoices', ['invoices' => collect($unrecorded)->map(fn (array $invoice): string => $invoice['number'].' ('.$invoice['customer'].', '.$invoice['total'].' '.$invoice['currency'].')')->implode('; ')]) }}
    </x-ui.alert>
@endif

<x-ui.card class="mb-6" :title="__('Receivables')" :description="__('Invoiced in the period, and what of it is paid, credited, written off and still owed.')" flush>
    <x-slot:actions><x-ui.button variant="ghost" size="sm" :href="route('admin.finance.reports.csv', ['report' => 'receivables', ...$period])">CSV</x-ui.button></x-slot:actions>
    <x-ui.table :primary="[0, 4, 9]">
        <x-slot:head>
            <x-ui.th>{{ __('Currency') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Invoices') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Net') }}</x-ui.th>
            <x-ui.th align="right">{{ __('VAT') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Total') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Paid') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Settled by credit') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Credited') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Written off') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Outstanding') }}</x-ui.th>
        </x-slot:head>
        @forelse ($receivables as $currency => $row)
            <tr class="hover:bg-sunken">
                <td class="px-6 py-3 text-sm font-medium text-fg">{{ $currency }}</td>
                <td class="px-6 py-3 text-right text-sm tabular-nums">{{ $row['count'] }}</td>
                @foreach (['net', 'vat', 'total', 'paid', 'by_credit', 'credited', 'written_off', 'outstanding'] as $column)
                    <td class="px-6 py-3 text-right text-sm"><x-ui.amount :value="$row[$column]" /></td>
                @endforeach
            </tr>
        @empty
            <x-ui.empty :columns="10">{{ __('Nothing invoiced in the period.') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</x-ui.card>

<x-ui.card class="mb-6" :title="__('Payments by method and currency')" :description="__('Received in the period, the fees the bank or gateway kept, and what was paid back.')" flush>
    <x-slot:actions><x-ui.button variant="ghost" size="sm" :href="route('admin.finance.reports.csv', ['report' => 'payments', ...$period])">CSV</x-ui.button></x-slot:actions>
    <x-ui.table :primary="[0, 1, 3]">
        <x-slot:head>
            <x-ui.th>{{ __('Method') }}</x-ui.th>
            <x-ui.th>{{ __('Currency') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Payments') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Received') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Fees') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Paid back') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Net') }}</x-ui.th>
        </x-slot:head>
        @forelse ($payments as $row)
            <tr class="hover:bg-sunken">
                <td class="px-6 py-3 text-sm font-medium text-fg">{{ $row['method'] }} <span class="block text-xs font-normal text-muted">{{ $row['channel'] }}</span></td>
                <td class="px-6 py-3 text-sm text-muted">{{ $row['currency'] }}</td>
                <td class="px-6 py-3 text-right text-sm tabular-nums">{{ $row['count'] }}</td>
                @foreach (['received', 'fees', 'paid_back', 'net'] as $column)
                    <td class="px-6 py-3 text-right text-sm"><x-ui.amount :value="$row[$column]" /></td>
                @endforeach
            </tr>
        @empty
            <x-ui.empty :columns="7">{{ __('Nothing received in the period.') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</x-ui.card>

<x-ui.card class="mb-6" :title="__('VAT on the platform\'s own invoices')" :description="__('Every document the billing organization issued in the period: invoices add, credit notes take away. In :currency at each document\'s recorded exchange rate, as the VAT return takes it.', ['currency' => $vat['tax_currency']])" flush>
    <x-slot:actions><x-ui.button variant="ghost" size="sm" :href="route('admin.finance.reports.csv', ['report' => 'vat', ...$period])">CSV</x-ui.button></x-slot:actions>
    <x-ui.table :primary="[0, 2, 7]">
        <x-slot:head>
            <x-ui.th>{{ __('Document') }}</x-ui.th>
            <x-ui.th>{{ __('Date') }}</x-ui.th>
            <x-ui.th>{{ __('Customer') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Net') }}</x-ui.th>
            <x-ui.th align="right">{{ __('VAT') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Exchange rate') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Net (:currency)', ['currency' => $vat['tax_currency']]) }}</x-ui.th>
            <x-ui.th align="right">{{ __('VAT (:currency)', ['currency' => $vat['tax_currency']]) }}</x-ui.th>
        </x-slot:head>
        @forelse ($vat['documents'] as $document)
            <tr class="hover:bg-sunken">
                <td class="px-6 py-3 text-sm font-medium text-fg" dir="ltr">{{ $document['number'] }}</td>
                <td class="px-6 py-3 text-sm text-muted">{{ $document['date'] }}</td>
                <td class="px-6 py-3 text-sm text-muted">{{ $document['customer'] }}</td>
                <td class="px-6 py-3 text-right text-sm"><x-ui.amount :value="$document['net']" :currency="$document['currency']" /></td>
                <td class="px-6 py-3 text-right text-sm"><x-ui.amount :value="$document['vat']" :currency="$document['currency']" /></td>
                <td class="px-6 py-3 text-right text-sm tabular-nums" dir="ltr">{{ $document['rate'] }}</td>
                <td class="px-6 py-3 text-right text-sm"><x-ui.amount :value="$document['net_tax']" /></td>
                <td class="px-6 py-3 text-right text-sm"><x-ui.amount :value="$document['vat_tax']" /></td>
            </tr>
        @empty
            <x-ui.empty :columns="8">{{ __('No document issued in the period.') }}</x-ui.empty>
        @endforelse
        <tr class="bg-sunken">
            <td class="px-6 py-3 text-sm font-semibold text-fg" colspan="6">{{ __('Output VAT for the period') }}</td>
            <td class="px-6 py-3 text-right text-sm font-semibold"><x-ui.amount :value="$vat['totals']['net']" :currency="$vat['tax_currency']" /></td>
            <td class="px-6 py-3 text-right text-sm font-semibold"><x-ui.amount :value="$vat['totals']['vat']" :currency="$vat['tax_currency']" /></td>
        </tr>
    </x-ui.table>
</x-ui.card>

<x-ui.card class="mb-6" :title="__('Collections')" :description="__('What fell due in the period, what was collected and how, how long invoices took to pay, and what is still owed on those that fell due before its end.')" flush>
    <x-slot:actions><x-ui.button variant="ghost" size="sm" :href="route('admin.finance.reports.csv', ['report' => 'collections', ...$period])">CSV</x-ui.button></x-slot:actions>
    <x-ui.table :primary="[0, 2, 6]">
        <x-slot:head>
            <x-ui.th>{{ __('Currency') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Fell due') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Collected') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Settled by credit') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Written off') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Average days to pay') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Overdue') }}</x-ui.th>
        </x-slot:head>
        @forelse ($collections as $currency => $row)
            <tr class="hover:bg-sunken">
                <td class="px-6 py-3 text-sm font-medium text-fg">{{ $currency }}</td>
                @foreach (['fell_due', 'collected', 'by_credit', 'written_off'] as $column)
                    <td class="px-6 py-3 text-right text-sm"><x-ui.amount :value="$row[$column]" /></td>
                @endforeach
                <td class="px-6 py-3 text-right text-sm tabular-nums">{{ $row['average_days_to_pay'] }}</td>
                <td class="px-6 py-3 text-right text-sm"><x-ui.amount :value="$row['overdue']" /> <span class="block text-xs text-muted">{{ __('Invoices: :count', ['count' => $row['overdue_count']]) }}</span></td>
            </tr>
        @empty
            <x-ui.empty :columns="7">{{ __('Nothing fell due or was collected in the period.') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</x-ui.card>

<div class="mb-3 flex items-center justify-between gap-3">
    <h2 class="text-lg font-semibold text-fg">{{ __('Owed by age on :date', ['date' => $period['to']]) }}</h2>
    <x-ui.button variant="ghost" size="sm" :href="route('admin.finance.reports.csv', ['report' => 'ageing', ...$period])">CSV</x-ui.button>
</div>
@forelse ($ageing as $currency => $table)
    <x-ui.card class="mb-6" :title="$currency" flush>
        @include('portal.partials.billing-ageing', ['table' => $table, 'headings' => $headings, 'currency' => $currency, 'customers' => true])
    </x-ui.card>
@empty
    <x-ui.alert tone="success">{{ __('Nothing is owed.') }}</x-ui.alert>
@endforelse
@endsection
