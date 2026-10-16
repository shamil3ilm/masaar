@extends('layouts.portal')

@section('title', __('Reports'))

@php
    // UaeVatSummary::CATEGORIES, as the portal reads them. The CSV keeps the English.
    $categoryLabels = [
        'S' => __('Standard rate'),
        'Z' => __('Zero-rated'),
        'E' => __('Exempt'),
        'O' => __('Out of scope'),
        'AE' => __('Reverse charge'),
        'N' => __('Margin scheme'),
    ];
@endphp

@section('content')
<div class="mb-6">
    <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ __('Reports') }}</h2>
    <p class="text-gray-500 dark:text-gray-400">{{ __('Your sales for a period by UAE tax category, in dirhams, and the invoices behind them.') }}</p>
</div>

@include('portal.partials.report-period')

@if ($summary['missing_rate'] !== [])
    {{-- Left out of the figures rather than converted at 1: the summary is in dirhams. --}}
    <div role="alert" class="mb-6 rounded border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/40 px-4 py-3 text-sm text-amber-800 dark:text-amber-200">
        {{ __(':count documents in another currency have no exchange rate, so they are not in the figures below. Give each its rate in dirhams: :numbers', ['count' => count($summary['missing_rate']), 'numbers' => implode(', ', array_slice($summary['missing_rate'], 0, 20)).(count($summary['missing_rate']) > 20 ? ' …' : '')]) }}
    </div>
@endif

@if (($summary['unreadable'] ?? []) !== [])
    <div role="alert" class="mb-6 rounded border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/40 px-4 py-3 text-sm text-amber-800 dark:text-amber-200">
        {{ __(':count invoices have a tax category the UAE does not use, so they are not in the figures below: :numbers', ['count' => count($summary['unreadable']), 'numbers' => implode(', ', array_slice($summary['unreadable'], 0, 20)).(count($summary['unreadable']) > 20 ? ' …' : '')]) }}
    </div>
@endif

<div class="mb-6 bg-white dark:bg-gray-900 rounded-lg shadow overflow-hidden">
    <div class="p-6 border-b flex flex-wrap items-center justify-between gap-3">
        <div>
            <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ __('Sales by tax category, :from to :to', ['from' => $from->toDateString(), 'to' => $to->toDateString()]) }}</h3>
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
            <x-ui.th>{{ __('Code') }}</x-ui.th>
            <x-ui.th>{{ __('Tax category') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Amount (AED)') }}</x-ui.th>
            <x-ui.th align="right">{{ __('VAT (AED)') }}</x-ui.th>
        </x-slot:head>
        @foreach ($summary['categories'] as $code => $values)
        <tr>
            <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $code }}</td>
            <td class="px-6 py-4 text-sm text-gray-800 dark:text-gray-100">{{ $categoryLabels[$code] ?? $values['label'] }}</td>
            @foreach (['base', 'vat'] as $key)
            <td class="px-6 py-4 text-end text-sm tabular-nums text-gray-800 dark:text-gray-100"><span dir="ltr">{{ number_format($values[$key], 2) }}</span></td>
            @endforeach
        </tr>
        @endforeach
        <tr class="font-semibold">
            <td class="px-6 py-4"></td>
            <td class="px-6 py-4 text-sm text-gray-800 dark:text-gray-100">{{ __('Total') }}</td>
            @foreach (['total_base', 'total_vat'] as $key)
            <td class="px-6 py-4 text-end text-sm tabular-nums text-gray-800 dark:text-gray-100"><span dir="ltr">{{ number_format($summary[$key], 2) }}</span></td>
            @endforeach
        </tr>
    </x-ui.table>
</div>

<p class="text-sm text-gray-500 dark:text-gray-400">
    {{ __('A working summary of your sales, not the FTA VAT return form: the return also splits standard-rated sales by emirate and asks for your purchases, which are not recorded here. Check the figures with your accountant before you file.') }}
</p>
@endsection
