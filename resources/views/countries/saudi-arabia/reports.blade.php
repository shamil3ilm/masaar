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
<x-ui.page-header :title="__('Reports')" :description="__('Your sales for a period, grouped as the VAT return asks, and the invoices behind them.')" />

@include('portal.partials.report-period')

@if ($summary['missing_rate'] !== [])
    {{-- Left out of the figures rather than converted at 1: the return is in riyals. --}}
    <x-ui.alert tone="warning" class="mb-6">
        {{ __(':count documents in another currency have no exchange rate, so they are not in the figures below. Give each its rate in riyals: :numbers', ['count' => count($summary['missing_rate']), 'numbers' => implode(', ', array_slice($summary['missing_rate'], 0, 20)).(count($summary['missing_rate']) > 20 ? ' …' : '')]) }}
    </x-ui.alert>
@endif

<div class="mb-6 bg-surface rounded-card border border-line shadow-card overflow-hidden">
    <div class="p-6 border-b flex flex-wrap items-center justify-between gap-3">
        <div>
            <h3 class="text-lg font-semibold text-fg">{{ __('VAT summary, :from to :to', ['from' => "\u{2066}".$from->toDateString()."\u{2069}", 'to' => "\u{2066}".$to->toDateString()."\u{2069}"]) }}</h3>
            <p class="text-sm text-muted">
                {{ __(':documents documents, :credit_notes of them credit notes, which are taken off.', ['documents' => $summary['documents'], 'credit_notes' => $summary['credit_notes']]) }}
                {{-- Guideline for Tax Invoicing and Records §7.1.1 (VatSummary). --}}
                @if ($summary['credit_notes'] > 0)
                    {{ __('A credit note for an invoice of this period is taken off its box; Adjustments hold only those for an earlier period.') }}
                @endif
                @if ($summary['adjustments'] > 0)
                    {{ __(':adjustments of them adjust invoices from an earlier period, so they are under Adjustments.', ['adjustments' => $summary['adjustments']]) }}
                @endif
                @if ($summary['converted'] > 0)
                    {{ __(':converted in another currency, converted at the rate on each invoice.', ['converted' => $summary['converted']]) }}
                @endif
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <x-ui.button variant="secondary" :href="route('portal.reports.close')">{{ __('Daily close by device') }}</x-ui.button>
            <x-ui.button variant="secondary" :href="route('portal.reports.insurers')">{{ __('Insurer receivables') }}</x-ui.button>
            <x-ui.button variant="secondary" :href="route('portal.reports.vat', ['from' => $from->toDateString(), 'to' => $to->toDateString()])">{{ __('VAT summary CSV') }}</x-ui.button>
            <x-ui.button variant="secondary" :href="route('portal.reports.invoices', ['from' => $from->toDateString(), 'to' => $to->toDateString()])">{{ __('Invoices CSV') }}</x-ui.button>
            {{-- The period's e-invoices as one ZIP, up to 31 days (DocumentArchive). --}}
            <x-ui.button variant="secondary" :href="route('portal.zatca.archive', ['from' => $from->toDateString(), 'to' => $to->toDateString()])">{{ __('E-invoice archive (ZIP)') }}</x-ui.button>
        </div>
    </div>
    <x-ui.table :primary="[0, 2, 4]">
        <x-slot:head>
            <x-ui.th>{{ __('Box') }}</x-ui.th>
            <x-ui.th>{{ __('Sales') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Amount (SAR)') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Adjustments (SAR)') }}</x-ui.th>
            <x-ui.th align="right">{{ __('VAT (SAR)') }}</x-ui.th>
            <x-ui.th align="right">{{ __('VAT adjustments (SAR)') }}</x-ui.th>
        </x-slot:head>
        @foreach ($summary['boxes'] as $box => $values)
        <tr>
            <td class="px-6 py-4 text-sm text-muted">{{ $box }}</td>
            <td class="px-6 py-4 text-sm text-fg">{{ $boxLabels[$box] ?? $values['label'] }}</td>
            @foreach (['base', 'adjustment_base', 'vat', 'adjustment_vat'] as $key)
            <td class="px-6 py-4 text-end text-sm tabular-nums text-fg"><x-ui.amount :value="$values[$key]" /></td>
            @endforeach
        </tr>
        @endforeach
        <tr class="font-semibold">
            <td class="px-6 py-4"></td>
            <td class="px-6 py-4 text-sm text-fg">{{ __('Total sales') }}</td>
            @foreach (['total_base', 'total_adjustment_base', 'total_vat', 'total_adjustment_vat'] as $key)
            <td class="px-6 py-4 text-end text-sm tabular-nums text-fg"><x-ui.amount :value="$summary[$key]" /></td>
            @endforeach
        </tr>
        <tr class="font-semibold">
            <td class="px-6 py-4"></td>
            <td class="px-6 py-4 text-sm text-fg">{{ __('Net of adjustments') }}</td>
            <td class="px-6 py-4 text-end text-sm tabular-nums text-fg"><x-ui.amount :value="$summary['net_base']" /></td>
            <td class="px-6 py-4"></td>
            <td class="px-6 py-4 text-end text-sm tabular-nums text-fg"><x-ui.amount :value="$summary['net_vat']" /></td>
            <td class="px-6 py-4"></td>
        </tr>
        @if ($summary['out_of_scope'] != 0)
        <tr>
            <td class="px-6 py-4"></td>
            <td class="px-6 py-4 text-sm text-muted">{{ __('Out of scope - not on the return') }}</td>
            <td class="px-6 py-4 text-end text-sm tabular-nums text-muted"><x-ui.amount :value="$summary['out_of_scope']" /></td>
            <td class="px-6 py-4" colspan="3"></td>
        </tr>
        @endif
        @if (($summary['disbursements'] ?? 0) != 0)
        <tr data-disbursements>
            <td class="px-6 py-4"></td>
            <td class="px-6 py-4 text-sm text-muted">{{ __('Of which disbursements paid on customers\' behalf') }}</td>
            <td class="px-6 py-4 text-end text-sm tabular-nums text-muted"><x-ui.amount :value="$summary['disbursements']" /></td>
            <td class="px-6 py-4" colspan="3"></td>
        </tr>
        @endif
    </x-ui.table>
</div>

{{-- The period branch by branch, beside the combined figures (BranchBreakdown). --}}
@include('portal.partials.branch-breakdown', ['rows' => $branchBreakdown ?? []])

<p class="text-sm text-muted">
    {{ __('Sales only: the return also asks for your purchases, which are not recorded here. Check the figures with your accountant before you file.') }}
</p>
@endsection
