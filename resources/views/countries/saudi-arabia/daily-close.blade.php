@extends('layouts.portal')

@section('title', __('Daily close'))

{{--
    One day's close (a Z-report), per device and per branch (DailyClose).

    @param \Carbon\CarbonImmutable $day
    @param array{day: string, devices: list<array>, branches: list<array>} $close
--}}

@section('content')
<div class="mb-6">
    <a href="{{ route('portal.reports') }}" class="text-sm text-teal-700 dark:text-teal-300 hover:underline">{{ __('Reports') }}</a>
    <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ __('Daily close') }}</h2>
    <p class="text-gray-500 dark:text-gray-400">{{ __('What each device and each branch issued on one day: the documents, the counters they used, and the amounts by VAT rate.') }}</p>
</div>

<form method="GET" action="{{ route('portal.reports.close') }}" class="mb-6 bg-white dark:bg-gray-900 rounded-lg shadow p-4 flex flex-wrap items-end gap-4">
    <div>
        <label for="day" class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">{{ __('Day') }}</label>
        <x-ui.input id="day" name="day" type="date" value="{{ $day->toDateString() }}" />
    </div>
    <x-ui.button variant="secondary">{{ __('Show') }}</x-ui.button>
    <x-ui.button variant="outline" :href="route('portal.reports.close.csv', ['day' => $day->toDateString()])">{{ __('Daily close CSV') }}</x-ui.button>
</form>

@foreach (['devices' => __('By device'), 'branches' => __('By branch')] as $section => $heading)
<div class="mb-6 bg-white dark:bg-gray-900 rounded-lg shadow overflow-hidden">
    <div class="p-6 border-b">
        <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ $heading }}</h3>
        @if ($section === 'devices')
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('ZATCA counts documents per device: a branch with its own certificate, or head office.') }}</p>
        @endif
    </div>

    @if ($close[$section] === [])
        <p class="p-6 text-sm text-gray-500 dark:text-gray-400">{{ __('Nothing was issued on this day.') }}</p>
    @else
    <x-ui.table>
        <x-slot:head>
            <x-ui.th>{{ __('Name') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Documents') }}</x-ui.th>
            <x-ui.th>{{ __('First') }}</x-ui.th>
            <x-ui.th>{{ __('Last') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Sales (SAR)') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Credit notes (SAR)') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Net (SAR)') }}</x-ui.th>
        </x-slot:head>
        @foreach ($close[$section] as $group)
        <tr>
            <td class="px-6 py-4 text-sm text-gray-800 dark:text-gray-100">
                {{ $group['name'] }}
                @if ($section === 'devices' && ! $group['contiguous'])
                    <span class="block text-xs text-amber-700 dark:text-amber-300">{{ __('The counters this day have a gap.') }}</span>
                @endif
            </td>
            <td class="px-6 py-4 text-end text-sm tabular-nums text-gray-800 dark:text-gray-100">
                {{ $group['documents'] }}
                <span class="block text-xs text-gray-500 dark:text-gray-400">{{ __(':invoices invoices, :credit credit notes, :debit debit notes', ['invoices' => $group['invoices'], 'credit' => $group['credit_notes'], 'debit' => $group['debit_notes']]) }}</span>
                @if ($group['rejected'] > 0)
                    <span class="block text-xs text-red-700 dark:text-red-300">{{ __(':count refused by ZATCA, not in the amounts', ['count' => $group['rejected']]) }}</span>
                @endif
            </td>
            @foreach (['first', 'last'] as $end)
            <td class="px-6 py-4 text-sm text-gray-800 dark:text-gray-100">
                <span dir="ltr">{{ $group[$end]['number'] ?? '' }}</span>
                <span class="block text-xs text-gray-500 dark:text-gray-400">{{ __('Counter :counter', ['counter' => $group[$end]['counter'] ?? '']) }}</span>
            </td>
            @endforeach
            <td class="px-6 py-4 text-end text-sm tabular-nums text-gray-800 dark:text-gray-100"><span dir="ltr">{{ number_format($group['sales']['total'], 2) }}</span></td>
            <td class="px-6 py-4 text-end text-sm tabular-nums text-gray-800 dark:text-gray-100"><span dir="ltr">{{ number_format(-$group['returns']['total'], 2) }}</span></td>
            <td class="px-6 py-4 text-end text-sm tabular-nums font-semibold text-gray-800 dark:text-gray-100"><span dir="ltr">{{ number_format($group['net'], 2) }}</span></td>
        </tr>
        @foreach ($group['rates'] as $rate)
        <tr>
            <td class="px-6 py-2 ps-10 text-xs text-gray-500 dark:text-gray-400" colspan="4">{{ __('VAT :rate%', ['rate' => $rate['rate']]) }}</td>
            <td class="px-6 py-2 text-end text-xs tabular-nums text-gray-500 dark:text-gray-400" colspan="2">{{ __('Net') }} <span dir="ltr">{{ number_format($rate['net'], 2) }}</span></td>
            <td class="px-6 py-2 text-end text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ __('VAT') }} <span dir="ltr">{{ number_format($rate['vat'], 2) }}</span></td>
        </tr>
        @endforeach
        @if ($group['missing_rate'] !== [])
        <tr>
            <td class="px-6 py-2 ps-10 text-xs text-amber-700 dark:text-amber-300" colspan="7">{{ __('Not in the amounts, no exchange rate: :numbers', ['numbers' => implode(', ', $group['missing_rate'])]) }}</td>
        </tr>
        @endif
        @endforeach
    </x-ui.table>
    @endif
</div>
@endforeach
@endsection
