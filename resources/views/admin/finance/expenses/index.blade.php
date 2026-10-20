@extends('layouts.admin')

@section('title', __('Expenses'))

@section('content')
{{--
    Finance › Expenses: what the platform spent, newest first, each in the
    currency it was billed in and in the tax currency. Drafts are expected
    bills not yet entered; void ones are kept and counted nowhere.
--}}
<x-ui.page-header :eyebrow="__('Finance')" :title="__('Expenses')"
    :description="__('What the platform spent, with its VAT and receipts. Amounts in the currency billed, and in the tax currency at the day\'s rate.')">
    @admincan('billing.manage')
    <x-slot:actions>
        <x-ui.button :href="route('admin.finance.expenses.create')">{{ __('Enter an expense') }}</x-ui.button>
    </x-slot:actions>
    @endadmincan
</x-ui.page-header>

<x-ui.list-filters :list="$list" />

<div class="overflow-hidden rounded-card border border-line bg-surface shadow-card">
    <x-ui.table :primary="[0, 1, 5]">
        <x-slot:head>
            <x-ui.th :list="$list" sort="date">{{ __('Date') }}</x-ui.th>
            <x-ui.th>{{ __('Supplier') }}</x-ui.th>
            <x-ui.th>{{ __('Category') }}</x-ui.th>
            <x-ui.th>{{ __('Status') }}</x-ui.th>
            <x-ui.th :list="$list" sort="due">{{ __('Due') }}</x-ui.th>
            <x-ui.th align="right" :list="$list" sort="amount">{{ __('Amount') }}</x-ui.th>
        </x-slot:head>
        @forelse ($expenses as $expense)
            <tr class="hover:bg-sunken">
                <td class="px-6 py-4 text-sm">
                    <a href="{{ route('admin.finance.expenses.show', $expense->id) }}" class="font-semibold text-accent hover:underline">{{ $expense->expense_date->toDateString() }}</a>
                    <span class="block text-xs text-muted">{{ $expense->description }}</span>
                </td>
                <td class="px-6 py-4 text-sm text-fg">{{ $expense->supplier?->name }}
                    @if ($expense->supplier_invoice_number)
                        <span class="block text-xs text-muted" dir="ltr">{{ $expense->supplier_invoice_number }}</span>
                    @endif
                </td>
                <td class="px-6 py-4 text-sm text-muted">{{ $categories[$expense->category] ?? $expense->category }}</td>
                <td class="px-6 py-4"><x-ui.badge :state="$expense->status->state()" :label="$expense->status->label()" /></td>
                <td class="px-6 py-4 text-sm text-muted">{{ $expense->due_date->toDateString() }}</td>
                <td class="px-6 py-4 text-right text-sm text-fg">
                    <x-ui.amount :value="$expense->gross" :currency="$expense->currency" />
                    @if ($expense->currency !== $expense->tax_currency)
                        <span class="block text-xs text-muted"><x-ui.amount :value="$expense->gross_tax" :currency="$expense->tax_currency" /></span>
                    @endif
                </td>
            </tr>
        @empty
            <x-ui.empty :columns="6">{{ __('No expense matches.') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

{{ $expenses->links() }}
@endsection
