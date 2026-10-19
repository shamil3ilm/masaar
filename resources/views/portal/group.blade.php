@extends('layouts.portal')

@section('title', __('Group: :group', ['group' => $group->name]))

@section('content')
<x-ui.page-header :eyebrow="__('Group')" :title="$group->name"
                  :description="__('The companies of the group you belong to, side by side and read-only. Each is a taxpayer of its own: its own invoices, numbering, filing and return.')" />

@include('portal.partials.report-period', ['periodRoute' => 'portal.reports.group'])

<div class="mb-6 overflow-hidden rounded-card border border-line bg-surface shadow-card">
    <div class="border-b border-line p-6">
        <h3 class="text-lg font-semibold text-fg">{{ __(':from to :to', ['from' => "\u{2066}".$from->toDateString()."\u{2069}", 'to' => "\u{2066}".$to->toDateString()."\u{2069}"]) }}</h3>
        <p class="text-sm text-muted">{{ __('Each company as its own country\'s report counts it, in its own currency. Credit notes are taken off.') }}</p>
    </div>
    <x-ui.table :primary="[0, 4, 2]">
        <x-slot:head>
            <x-ui.th>{{ __('Company') }}</x-ui.th>
            <x-ui.th>{{ __('Country') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Documents') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Net') }}</x-ui.th>
            <x-ui.th align="right">{{ __('VAT') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Actions') }}</x-ui.th>
        </x-slot:head>
        @foreach ($rows as $row)
            <tr>
                <td class="px-6 py-4 text-sm">
                    <div class="font-medium text-fg">{{ $row['name'] }}</div>
                    @if ($row['restricted'])
                        <div class="text-xs text-subtle">{{ __('Your branches only') }}</div>
                    @endif
                </td>
                <td class="px-6 py-4 text-sm text-muted">{{ __($row['country']) }}</td>
                <td class="px-6 py-4 text-end text-sm tabular-nums text-muted">{{ $row['documents'] }}</td>
                <td class="px-6 py-4 text-end text-sm tabular-nums text-fg"><x-ui.amount :value="$row['base']" :currency="$row['currency']" /></td>
                <td class="px-6 py-4 text-end text-sm tabular-nums text-fg"><x-ui.amount :value="$row['vat']" :currency="$row['currency']" /></td>
                <td class="px-6 py-4 text-end text-sm">
                    <a href="{{ route('portal.dashboard', ['org_id' => $row['id']]) }}" class="text-accent hover:underline">{{ __('Open') }}</a>
                </td>
            </tr>
        @endforeach
        @foreach ($sums as $currency => $sum)
            <tr class="font-semibold">
                <td class="px-6 py-4 text-sm text-fg">{{ __('All companies in :currency', ['currency' => $currency]) }}</td>
                <td class="px-6 py-4"></td>
                <td class="px-6 py-4 text-end text-sm tabular-nums text-fg">{{ $sum['documents'] }}</td>
                <td class="px-6 py-4 text-end text-sm tabular-nums text-fg"><x-ui.amount :value="$sum['base']" :currency="$currency" /></td>
                <td class="px-6 py-4 text-end text-sm tabular-nums text-fg"><x-ui.amount :value="$sum['vat']" :currency="$currency" /></td>
                <td class="px-6 py-4"></td>
            </tr>
        @endforeach
    </x-ui.table>
</div>

<p class="text-sm text-muted">{{ __('Amounts in different currencies are not added together or converted. Each company files its own return.') }}</p>
@endsection
