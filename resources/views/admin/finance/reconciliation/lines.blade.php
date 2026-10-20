@extends('layouts.admin')

@section('title', __('Statement lines'))

@section('content')
<x-ui.page-header :title="__('Statement lines')" :description="__('Every line of every statement imported, and where it stands. Open a line to confirm, reject or split its suggestions, or to match it by hand.')" />

@include('admin.finance.reconciliation.nav', ['current' => 'lines'])

<x-ui.list-filters :list="$list" />

<div class="bg-surface rounded-card border border-line shadow-card overflow-hidden">
    <x-ui.table :primary="[0, 2, 3]">
        <x-slot:head>
            <x-ui.th :list="$list" sort="booked">{{ __('Date') }}</x-ui.th>
            <x-ui.th>{{ __('Reference') }}</x-ui.th>
            <x-ui.th align="right" :list="$list" sort="amount">{{ __('Amount') }}</x-ui.th>
            <x-ui.th>{{ __('Status') }}</x-ui.th>
            <x-ui.th>{{ __('Payer') }}</x-ui.th>
        </x-slot:head>
        @forelse ($lines as $line)
            <tr class="hover:bg-sunken">
                <td class="px-6 py-4 text-sm text-fg"><x-ui.date :value="$line->booked_on" /></td>
                <td class="px-6 py-4 text-sm">
                    <a href="{{ route('admin.finance.reconciliation.line', $line->id) }}" class="text-accent hover:underline" dir="ltr">{{ $line->reference ?? $line->bank_reference ?? __('No reference') }}</a>
                    <span class="block max-w-md truncate text-xs text-muted">{{ $line->description }}</span>
                </td>
                <td class="px-6 py-4 text-right text-sm {{ $line->isCredit() ? 'text-fg' : 'text-muted' }}">
                    {{ $line->isCredit() ? '' : '−' }}<x-ui.amount :value="$line->amount" :currency="$line->currency" />
                </td>
                <td class="px-6 py-4"><x-ui.badge :tone="$line->isCredit() ? $line->status->tone() : 'neutral'" :label="$line->isCredit() ? $line->status->label() : __('Money out')" /></td>
                <td class="px-6 py-4 text-sm text-muted">{{ $line->counterparty_name }}</td>
            </tr>
        @empty
            <x-ui.empty :columns="5">{{ __('No line matches.') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

{{ $lines->links() }}
@endsection
