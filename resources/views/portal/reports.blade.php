@extends('layouts.portal')

@section('title', __('Reports'))

@php
    // VatSummary::BOXES, as the portal reads them. The CSV keeps the English.
    $boxLabels = [
        1 => __('Standard rated sales'),
        2 => __('Sales to citizens (private healthcare, private education)'),
        3 => __('Zero rated domestic sales'),
        4 => __('Exports'),
        5 => __('Exempt sales'),
    ];
@endphp

@section('content')
<div class="mb-6">
    <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ __('Reports') }}</h2>
    <p class="text-gray-500 dark:text-gray-400">{{ __('Your sales for a period, grouped as the VAT return asks, and the invoices behind them.') }}</p>
</div>

<form method="GET" action="{{ route('portal.reports') }}" class="mb-4 bg-white dark:bg-gray-900 rounded-lg shadow p-4 flex flex-wrap items-end gap-4">
    <div>
        <label for="from" class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">{{ __('From') }}</label>
        <x-ui.input id="from" name="from" type="date" value="{{ $from->toDateString() }}" />
    </div>
    <div>
        <label for="to" class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">{{ __('To') }}</label>
        <x-ui.input id="to" name="to" type="date" value="{{ $to->toDateString() }}" />
    </div>
    <x-ui.button variant="secondary">{{ __('Show') }}</x-ui.button>
</form>

<p class="mb-6 flex flex-wrap gap-3 text-sm">
    @foreach ($presets as $label => $range)
        <a href="{{ route('portal.reports', $range) }}" class="text-teal-700 dark:text-teal-300 hover:underline">{{ $label }}</a>
    @endforeach
</p>

<div class="mb-6 bg-white dark:bg-gray-900 rounded-lg shadow overflow-hidden">
    <div class="p-6 border-b flex flex-wrap items-center justify-between gap-3">
        <div>
            <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ __('VAT summary, :from to :to', ['from' => $from->toDateString(), 'to' => $to->toDateString()]) }}</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __(':documents documents, :credit_notes of them credit notes, which are taken off.', ['documents' => $summary['documents'], 'credit_notes' => $summary['credit_notes']]) }}
                @if ($summary['converted'] > 0)
                    {{ __(':converted in another currency, converted at the rate on each invoice.', ['converted' => $summary['converted']]) }}
                @endif
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <x-ui.button variant="outline" :href="route('portal.reports.vat', ['from' => $from->toDateString(), 'to' => $to->toDateString()])">{{ __('VAT summary CSV') }}</x-ui.button>
            <x-ui.button variant="outline" :href="route('portal.reports.invoices', ['from' => $from->toDateString(), 'to' => $to->toDateString()])">{{ __('Invoices CSV') }}</x-ui.button>
        </div>
    </div>
    <x-ui.table>
        <x-slot:head>
            <x-ui.th>{{ __('Box') }}</x-ui.th>
            <x-ui.th>{{ __('Sales') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Amount (SAR)') }}</x-ui.th>
            <x-ui.th align="right">{{ __('VAT (SAR)') }}</x-ui.th>
        </x-slot:head>
        @foreach ($summary['boxes'] as $box => $values)
        <tr>
            <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $box }}</td>
            <td class="px-6 py-4 text-sm text-gray-800 dark:text-gray-100">{{ $boxLabels[$box] ?? $values['label'] }}</td>
            <td class="px-6 py-4 text-end text-sm tabular-nums text-gray-800 dark:text-gray-100"><span dir="ltr">{{ number_format($values['base'], 2) }}</span></td>
            <td class="px-6 py-4 text-end text-sm tabular-nums text-gray-800 dark:text-gray-100"><span dir="ltr">{{ number_format($values['vat'], 2) }}</span></td>
        </tr>
        @endforeach
        <tr class="font-semibold">
            <td class="px-6 py-4"></td>
            <td class="px-6 py-4 text-sm text-gray-800 dark:text-gray-100">{{ __('Total sales') }}</td>
            <td class="px-6 py-4 text-end text-sm tabular-nums text-gray-800 dark:text-gray-100"><span dir="ltr">{{ number_format($summary['total_base'], 2) }}</span></td>
            <td class="px-6 py-4 text-end text-sm tabular-nums text-gray-800 dark:text-gray-100"><span dir="ltr">{{ number_format($summary['total_vat'], 2) }}</span></td>
        </tr>
        @if ($summary['out_of_scope'] != 0)
        <tr>
            <td class="px-6 py-4"></td>
            <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ __('Out of scope - not on the return') }}</td>
            <td class="px-6 py-4 text-end text-sm tabular-nums text-gray-500 dark:text-gray-400"><span dir="ltr">{{ number_format($summary['out_of_scope'], 2) }}</span></td>
            <td class="px-6 py-4"></td>
        </tr>
        @endif
    </x-ui.table>
</div>

<p class="text-sm text-gray-500 dark:text-gray-400">
    {{ __('Sales only: the return also asks for your purchases, which are not recorded here. Check the figures with your accountant before you file.') }}
</p>
@endsection
