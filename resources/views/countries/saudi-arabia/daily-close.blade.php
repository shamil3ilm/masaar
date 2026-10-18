@extends('layouts.portal')

@section('title', __('Daily close'))

{{--
    One day's close (a Z-report), per device and per branch (DailyClose).

    @param \Carbon\CarbonImmutable $day
    @param array{day: string, devices: list<array>, branches: list<array>} $close
--}}

@section('content')
<x-ui.page-header :title="__('Daily close')"
                  :description="__('What each device and each branch issued on one day: the documents, the counters they used, and the amounts by VAT rate.')">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('Reports'), 'href' => route('portal.reports')], ['label' => __('Daily close')]]" />
    </x-slot:breadcrumb>
</x-ui.page-header>

<form method="GET" action="{{ route('portal.reports.close') }}" class="mb-6 flex flex-wrap items-end gap-3 rounded-card border border-line bg-surface p-4 shadow-card">
    <x-ui.field :label="__('Day')">
        <x-ui.input id="day" name="day" type="date" value="{{ $day->toDateString() }}" />
    </x-ui.field>
    <x-ui.button variant="secondary">{{ __('Show') }}</x-ui.button>
    <x-ui.button variant="secondary" :href="route('portal.reports.close.csv', ['day' => $day->toDateString()])">{{ __('Daily close CSV') }}</x-ui.button>
</form>

@foreach (['devices' => __('By device'), 'branches' => __('By branch')] as $section => $heading)
<x-ui.card class="mb-6 overflow-hidden" flush :title="$heading"
           :description="$section === 'devices' ? __('ZATCA counts documents per device: a branch with its own certificate, or head office.') : null">

    @if ($close[$section] === [])
        <p class="p-6 text-sm text-muted">{{ __('Nothing was issued on this day.') }}</p>
    @else
    <x-ui.table :primary="[0, 6, 1]">
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
            <td class="px-6 py-4 text-sm text-fg">
                {{ $group['name'] }}
                @if ($section === 'devices' && ! $group['contiguous'])
                    <span class="block text-xs text-warning">{{ __('The counters this day have a gap.') }}</span>
                @endif
            </td>
            <td class="px-6 py-4 text-end text-sm tabular-nums text-fg">
                {{ $group['documents'] }}
                <span class="block text-xs text-muted">{{ __(':invoices invoices, :credit credit notes, :debit debit notes', ['invoices' => $group['invoices'], 'credit' => $group['credit_notes'], 'debit' => $group['debit_notes']]) }}</span>
                @if ($group['rejected'] > 0)
                    <span class="block text-xs text-danger">{{ __(':count refused by ZATCA, not in the amounts', ['count' => $group['rejected']]) }}</span>
                @endif
            </td>
            @foreach (['first', 'last'] as $end)
            <td class="px-6 py-4 text-sm text-fg">
                <span dir="ltr">{{ $group[$end]['number'] ?? '' }}</span>
                <span class="block text-xs text-muted">{{ __('Counter :counter', ['counter' => $group[$end]['counter'] ?? '']) }}</span>
            </td>
            @endforeach
            <td class="px-6 py-4 text-end text-sm tabular-nums text-fg"><span dir="ltr">{{ number_format($group['sales']['total'], 2) }}</span></td>
            <td class="px-6 py-4 text-end text-sm tabular-nums text-fg"><span dir="ltr">{{ number_format(-$group['returns']['total'], 2) }}</span></td>
            <td class="px-6 py-4 text-end text-sm tabular-nums font-semibold text-fg"><span dir="ltr">{{ number_format($group['net'], 2) }}</span></td>
        </tr>
        @foreach ($group['rates'] as $rate)
        <tr>
            <td class="px-6 py-2 ps-10 text-xs text-muted" colspan="4">{{ __('VAT :rate%', ['rate' => $rate['rate']]) }}</td>
            <td class="px-6 py-2 text-end text-xs tabular-nums text-muted" colspan="2">{{ __('Net') }} <span dir="ltr">{{ number_format($rate['net'], 2) }}</span></td>
            <td class="px-6 py-2 text-end text-xs tabular-nums text-muted">{{ __('VAT') }} <span dir="ltr">{{ number_format($rate['vat'], 2) }}</span></td>
        </tr>
        @endforeach
        @if ($group['missing_rate'] !== [])
        <tr>
            <td class="px-6 py-2 ps-10 text-xs text-warning" colspan="7">{{ __('Not in the amounts, no exchange rate: :numbers', ['numbers' => implode(', ', $group['missing_rate'])]) }}</td>
        </tr>
        @endif
        @endforeach
    </x-ui.table>
    @endif
</x-ui.card>
@endforeach
@endsection
