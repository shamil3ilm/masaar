@extends('layouts.admin')

@section('title', __('Payables'))

@section('content')
{{--
    Finance › Payables: what the platform owes its suppliers - recorded
    expenses not yet paid or written off - by how long past due, and each
    one, the one due first first.
--}}
<x-ui.page-header :eyebrow="__('Finance')" :title="__('Payables')"
    :description="__('What the platform owes its suppliers, by how long past due. Pay one from its expense.')" />

@forelse ($ageing as $currency => $table)
    <x-ui.card class="mb-6" :title="__('Owed by age (:currency)', ['currency' => $currency])" flush>
        @include('admin.finance.partials.payables-ageing', ['table' => $table, 'headings' => $headings, 'currency' => $currency])
    </x-ui.card>
@empty
    <x-ui.alert tone="success" class="mb-6">{{ __('Nothing is owed to suppliers.') }}</x-ui.alert>
@endforelse

<x-ui.list-filters :list="$list" />

<div class="overflow-hidden rounded-card border border-line bg-surface shadow-card">
    <x-ui.table :primary="[0, 1, 4]">
        <x-slot:head>
            <x-ui.th :list="$list" sort="due">{{ __('Due') }}</x-ui.th>
            <x-ui.th>{{ __('Supplier') }}</x-ui.th>
            <x-ui.th>{{ __('Status') }}</x-ui.th>
            <x-ui.th align="right" :list="$list" sort="amount">{{ __('Total') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Owed') }}</x-ui.th>
        </x-slot:head>
        @forelse ($payables as $expense)
            <tr class="hover:bg-sunken">
                <td class="px-6 py-4 text-sm"><a href="{{ route('admin.finance.expenses.show', $expense->id) }}" class="font-semibold text-accent hover:underline">{{ $expense->due_date->toDateString() }}</a>
                    <span class="block text-xs text-muted">{{ $expense->description }}</span></td>
                <td class="px-6 py-4 text-sm text-fg">{{ $expense->supplier?->name }}
                    @if ($expense->supplier_invoice_number)
                        <span class="block text-xs text-muted" dir="ltr">{{ $expense->supplier_invoice_number }}</span>
                    @endif
                </td>
                <td class="px-6 py-4"><x-ui.badge :state="$expense->daysOverdue(now()->toImmutable()) > 0 ? 'overdue' : $expense->status->state()" :label="$expense->daysOverdue(now()->toImmutable()) > 0 ? __('Overdue') : $expense->status->label()" /></td>
                <td class="px-6 py-4 text-right text-sm text-muted"><x-ui.amount :value="$expense->gross" :currency="$expense->currency" /></td>
                <td class="px-6 py-4 text-right text-sm text-fg"><x-ui.amount :value="$expense->balance()" :currency="$expense->currency" /></td>
            </tr>
        @empty
            <x-ui.empty :columns="5">{{ __('Nothing owed matches.') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

{{ $payables->links() }}
@endsection
