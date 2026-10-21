@extends('layouts.portal')

@section('title', __('Statement of account'))

{{--
    A customer's statement for a period, in one currency (CustomerStatements).

    @param \App\Domains\Invoice\Models\Customer $customer
    @param array<string, mixed> $statement
    @param \Carbon\CarbonImmutable $from
    @param \Carbon\CarbonImmutable $to
--}}

@section('content')
@php($query = ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'currency' => $statement['currency']])
<x-ui.page-header :title="__('Statement of account')" :description="$customer->name">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('Books'), 'href' => route('portal.books.index')], ['label' => __('Receivables'), 'href' => route('portal.books.receivables')], ['label' => $customer->name]]" />
    </x-slot:breadcrumb>
    <x-slot:actions>
        <x-ui.button variant="secondary" :href="route('portal.books.statements.pdf', [$customer->id, ...$query])">{{ __('Download PDF') }}</x-ui.button>
    </x-slot:actions>
</x-ui.page-header>

<x-ui.section :title="__('Period')" collapse class="mb-6">
    <form method="GET" action="{{ route('portal.books.statements.show', $customer->id) }}" class="grid grid-cols-1 gap-4 sm:grid-cols-4">
        <x-ui.field :label="__('From')"><x-ui.date-input name="from" value="{{ $from->toDateString() }}" /></x-ui.field>
        <x-ui.field :label="__('To')"><x-ui.date-input name="to" value="{{ $to->toDateString() }}" /></x-ui.field>
        @if (count($statement['currencies']) > 1)
            <x-ui.field :label="__('Currency')">
                <x-ui.select name="currency">
                    @foreach ($statement['currencies'] as $code)
                        <option value="{{ $code }}" @selected($code === $statement['currency'])>{{ $code }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
        @endif
        <div class="flex items-end"><x-ui.button>{{ __('Show') }}</x-ui.button></div>
    </form>
</x-ui.section>

<div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
    <x-ui.stat :label="__('Opening balance')" :value="\App\Domains\Books\Services\Purchases::money($statement['opening']).' '.$statement['currency']" />
    <x-ui.stat :label="__('Billed')" :value="\App\Domains\Books\Services\Purchases::money($statement['debits'])" />
    <x-ui.stat :label="__('Paid and credited')" :value="\App\Domains\Books\Services\Purchases::money($statement['credits'])" />
    <x-ui.stat :label="__('Closing balance')" :value="\App\Domains\Books\Services\Purchases::money($statement['closing']).' '.$statement['currency']" />
</div>

<x-ui.card class="overflow-hidden" flush>
    <x-ui.table :primary="[1, 2, 5]">
        <x-slot:head>
            <x-ui.th>{{ __('Date') }}</x-ui.th>
            <x-ui.th>{{ __('Entry') }}</x-ui.th>
            <x-ui.th>{{ __('Reference') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Debit') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Credit') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Balance') }}</x-ui.th>
        </x-slot:head>
        @forelse ($statement['lines'] as $line)
            <tr>
                <td class="px-6 py-3 text-sm"><x-ui.date :value="$line['date']" /></td>
                <td class="px-6 py-3 text-sm text-fg">{{ $line['kind'] }}</td>
                <td class="px-6 py-3 text-sm text-muted" dir="ltr">{{ $line['reference'] }}</td>
                <td class="px-6 py-3 text-end text-sm"><x-ui.amount :value="$line['debit']" /></td>
                <td class="px-6 py-3 text-end text-sm"><x-ui.amount :value="$line['credit']" /></td>
                <td class="px-6 py-3 text-end text-sm font-semibold"><x-ui.amount :value="$line['balance']" /></td>
            </tr>
        @empty
            <x-ui.empty :columns="6">{{ __('Nothing was billed, paid or credited in this period.') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</x-ui.card>
@endsection
