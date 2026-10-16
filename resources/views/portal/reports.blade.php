@extends('layouts.portal')

@section('title', 'Reports')

@section('content')
<div class="mb-6">
    <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Reports</h2>
    <p class="text-gray-500 dark:text-gray-400">Your sales for a period, grouped as the VAT return asks, and the invoices behind them.</p>
</div>

<form method="GET" action="{{ route('portal.reports') }}" class="mb-4 bg-white dark:bg-gray-900 rounded-lg shadow p-4 flex flex-wrap items-end gap-4">
    <div>
        <label for="from" class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">From</label>
        <x-ui.input id="from" name="from" type="date" value="{{ $from->toDateString() }}" />
    </div>
    <div>
        <label for="to" class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">To</label>
        <x-ui.input id="to" name="to" type="date" value="{{ $to->toDateString() }}" />
    </div>
    <x-ui.button variant="secondary">Show</x-ui.button>
</form>

<p class="mb-6 flex flex-wrap gap-3 text-sm">
    @foreach ($presets as $label => $range)
        <a href="{{ route('portal.reports', $range) }}" class="text-teal-700 dark:text-teal-300 hover:underline">{{ $label }}</a>
    @endforeach
</p>

<div class="mb-6 bg-white dark:bg-gray-900 rounded-lg shadow overflow-hidden">
    <div class="p-6 border-b flex flex-wrap items-center justify-between gap-3">
        <div>
            <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">VAT summary, {{ $from->toDateString() }} to {{ $to->toDateString() }}</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ $summary['documents'] }} documents, {{ $summary['credit_notes'] }} of them credit notes, which are taken off.
                @if ($summary['converted'] > 0)
                    {{ $summary['converted'] }} in another currency, converted at the rate on each invoice.
                @endif
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <x-ui.button variant="outline" :href="route('portal.reports.vat', ['from' => $from->toDateString(), 'to' => $to->toDateString()])">VAT summary CSV</x-ui.button>
            <x-ui.button variant="outline" :href="route('portal.reports.invoices', ['from' => $from->toDateString(), 'to' => $to->toDateString()])">Invoices CSV</x-ui.button>
        </div>
    </div>
    <x-ui.table>
        <x-slot:head>
            <x-ui.th>Box</x-ui.th>
            <x-ui.th>Sales</x-ui.th>
            <x-ui.th align="right">Amount (SAR)</x-ui.th>
            <x-ui.th align="right">VAT (SAR)</x-ui.th>
        </x-slot:head>
        @foreach ($summary['boxes'] as $box => $values)
        <tr>
            <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $box }}</td>
            <td class="px-6 py-4 text-sm text-gray-800 dark:text-gray-100">{{ $values['label'] }}</td>
            <td class="px-6 py-4 text-right text-sm tabular-nums text-gray-800 dark:text-gray-100">{{ number_format($values['base'], 2) }}</td>
            <td class="px-6 py-4 text-right text-sm tabular-nums text-gray-800 dark:text-gray-100">{{ number_format($values['vat'], 2) }}</td>
        </tr>
        @endforeach
        <tr class="font-semibold">
            <td class="px-6 py-4"></td>
            <td class="px-6 py-4 text-sm text-gray-800 dark:text-gray-100">Total sales</td>
            <td class="px-6 py-4 text-right text-sm tabular-nums text-gray-800 dark:text-gray-100">{{ number_format($summary['total_base'], 2) }}</td>
            <td class="px-6 py-4 text-right text-sm tabular-nums text-gray-800 dark:text-gray-100">{{ number_format($summary['total_vat'], 2) }}</td>
        </tr>
        @if ($summary['out_of_scope'] != 0)
        <tr>
            <td class="px-6 py-4"></td>
            <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">Out of scope - not on the return</td>
            <td class="px-6 py-4 text-right text-sm tabular-nums text-gray-500 dark:text-gray-400">{{ number_format($summary['out_of_scope'], 2) }}</td>
            <td class="px-6 py-4"></td>
        </tr>
        @endif
    </x-ui.table>
</div>

<p class="text-sm text-gray-500 dark:text-gray-400">
    Sales only: the return also asks for your purchases, which are not recorded here. Check the figures with your accountant before you file.
</p>
@endsection
