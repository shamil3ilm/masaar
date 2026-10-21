@extends('layouts.admin')

@section('title', __('Expense reports'))

@section('content')
@php
    $period = ['from' => $from->toDateString(), 'to' => $to->toDateString()];
    $groupTitles = [
        'category' => __('By category'),
        'supplier' => __('By supplier'),
        'month' => __('By month'),
        'cost_centre' => __('By cost centre'),
    ];
    $summary = $vat['summary'];
@endphp
<x-ui.page-header :eyebrow="__('Finance')" :title="__('Expense reports')" :description="__('What the platform spent, the VAT on it for the return, what it owes its suppliers, and its profit and loss. Every amount in :currency at each expense\'s recorded rate.', ['currency' => $taxCurrency])" />

<form method="GET" action="{{ route('admin.finance.expense-reports') }}" class="mb-6 flex flex-wrap items-end gap-4">
    <x-ui.field :label="__('From')">
        <x-ui.date-input name="from" value="{{ $period['from'] }}" />
    </x-ui.field>
    <x-ui.field :label="__('To')">
        <x-ui.date-input name="to" value="{{ $period['to'] }}" />
    </x-ui.field>
    <x-ui.button variant="secondary">{{ __('Show') }}</x-ui.button>
</form>

{{-- The VAT return's figures from the platform's side: output VAT on its
     sales and on reverse charge, input VAT it deducts. --}}
<x-ui.card class="mb-6" :title="__('VAT return draft (:currency)', ['currency' => $taxCurrency])" :description="__('Output VAT on the platform\'s own invoices and on services from suppliers abroad (reverse charge), and the input VAT it deducts. Reverse charge is on both sides of the same return.')" flush data-vat-return>
    <x-slot:actions><x-ui.button variant="ghost" size="sm" :href="route('admin.finance.expense-reports.csv', ['report' => 'vat', ...$period])">CSV</x-ui.button></x-slot:actions>
    <x-ui.table :primary="[0, 1]" collapsible="never">
        <x-slot:head>
            <x-ui.th>{{ __('Line') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Amount') }}</x-ui.th>
        </x-slot:head>
        @foreach ([
            'sales_net' => __('Sales before VAT'),
            'sales_output' => __('Output VAT on sales'),
            'reverse_charge_base' => __('Imported services under reverse charge'),
            'reverse_charge_output' => __('Output VAT on reverse charge'),
            'purchases_net' => __('Purchases before VAT'),
            'input_recoverable' => __('Input VAT deducted on purchases'),
            'reverse_charge_input' => __('Input VAT deducted on reverse charge'),
            'input_not_recoverable' => __('Input VAT not deducted (a cost)'),
            'output_total' => __('Output VAT, total'),
            'input_total' => __('Input VAT, total'),
            'net_payable' => __('Net VAT payable (refundable when negative)'),
        ] as $line => $label)
            <tr @class(['hover:bg-sunken', 'bg-sunken font-semibold' => in_array($line, ['output_total', 'input_total', 'net_payable'], true)])>
                <td class="px-6 py-3 text-sm text-fg">{{ $label }}</td>
                <td class="px-6 py-3 text-right text-sm"><x-ui.amount :value="$summary[$line]" /></td>
            </tr>
        @endforeach
    </x-ui.table>
</x-ui.card>

@php($pnlHeadings = \App\Domains\Billing\Services\ExpenseReports::pnlHeadings())
<x-ui.card class="mb-6" :title="__('Profit and loss by month (:currency)', ['currency' => $taxCurrency])" :description="__('An outline: revenue is the platform\'s own invoices before VAT, less credit notes; costs are what its expenses cost it after the VAT it deducts; exchange differences are realised on paying suppliers and on customers\' payments in another currency, a gain adding; fees are what the bank or card gateway kept; bad debts are invoices written off with their VAT; supplier bills written off are a gain; goodwill credits are credit given and taken by invoices.')" flush>
    <x-slot:actions><x-ui.button variant="ghost" size="sm" :href="route('admin.finance.expense-reports.csv', ['report' => 'pnl', ...$period])">CSV</x-ui.button></x-slot:actions>
    <x-ui.table :primary="[0, count($pnlHeadings) + 1]">
        <x-slot:head>
            <x-ui.th>{{ __('Month') }}</x-ui.th>
            @foreach ($pnlHeadings as $heading)
                <x-ui.th align="right">{{ $heading }}</x-ui.th>
            @endforeach
            <x-ui.th align="right">{{ __('Profit') }}</x-ui.th>
        </x-slot:head>
        @foreach ($pnl['months'] as $row)
            <tr class="hover:bg-sunken">
                <td class="px-6 py-3 text-sm font-medium text-fg">{{ $row['month'] }}</td>
                @foreach ([...array_keys($pnlHeadings), 'profit'] as $column)
                    <td class="px-6 py-3 text-right text-sm"><x-ui.amount :value="$row[$column]" /></td>
                @endforeach
            </tr>
        @endforeach
        <tr class="bg-sunken">
            <td class="px-6 py-3 text-sm font-semibold text-fg">{{ __('Total') }}</td>
            @foreach (array_keys($pnlHeadings) as $column)
                <td class="px-6 py-3 text-right text-sm font-semibold"><x-ui.amount :value="$pnl['totals'][$column]" /></td>
            @endforeach
            <td class="px-6 py-3 text-right text-sm font-semibold"><x-ui.amount :value="$pnl['totals']['profit']" :currency="$taxCurrency" /></td>
        </tr>
    </x-ui.table>
    @if ($pnl['unconverted'] !== [])
        <div class="px-6 py-3"><x-ui.alert tone="warning">{{ __('Left out, with no exchange rate on record or in the rate table: :lines', ['lines' => implode('; ', $pnl['unconverted'])]) }}</x-ui.alert></div>
    @endif
</x-ui.card>

@foreach ($groups as $by => $rows)
    <x-ui.card class="mb-6" :title="$groupTitles[$by]" flush>
        <x-slot:actions><x-ui.button variant="ghost" size="sm" :href="route('admin.finance.expense-reports.csv', ['report' => $by, ...$period])">CSV</x-ui.button></x-slot:actions>
        <x-ui.table :primary="[0, 5]">
            <x-slot:head>
                <x-ui.th>{{ $groupTitles[$by] }}</x-ui.th>
                <x-ui.th align="right">{{ __('Expenses') }}</x-ui.th>
                <x-ui.th align="right">{{ __('Net') }}</x-ui.th>
                <x-ui.th align="right">{{ __('VAT') }}</x-ui.th>
                <x-ui.th align="right">{{ __('Deducted') }}</x-ui.th>
                <x-ui.th align="right">{{ __('Cost') }}</x-ui.th>
            </x-slot:head>
            @forelse ($rows as $row)
                <tr class="hover:bg-sunken">
                    <td class="px-6 py-3 text-sm font-medium text-fg">{{ $row['name'] }}</td>
                    <td class="px-6 py-3 text-right text-sm tabular-nums">{{ $row['count'] }}</td>
                    @foreach (['net', 'vat', 'recoverable', 'cost'] as $column)
                        <td class="px-6 py-3 text-right text-sm"><x-ui.amount :value="$row[$column]" /></td>
                    @endforeach
                </tr>
            @empty
                <x-ui.empty :columns="6">{{ __('No expense in the period.') }}</x-ui.empty>
            @endforelse
        </x-ui.table>
    </x-ui.card>
@endforeach

<div class="mb-3 flex items-center justify-between gap-3">
    <h2 class="text-lg font-semibold text-fg">{{ __('Owed to suppliers by age on :date', ['date' => $period['to']]) }}</h2>
    <x-ui.button variant="ghost" size="sm" :href="route('admin.finance.expense-reports.csv', ['report' => 'payables', ...$period])">CSV</x-ui.button>
</div>
@forelse ($payables as $currency => $table)
    <x-ui.card class="mb-6" :title="$currency" flush>
        @include('admin.finance.partials.payables-ageing', ['table' => $table, 'headings' => $headings, 'currency' => $currency])
    </x-ui.card>
@empty
    <x-ui.alert tone="success">{{ __('Nothing is owed to suppliers.') }}</x-ui.alert>
@endforelse
@endsection
