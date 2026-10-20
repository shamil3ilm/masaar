@extends('layouts.admin')

@section('title', __('Recurring costs'))

@section('content')
{{--
    Finance › Recurring costs: hosting, providers' fees, subscriptions. Each
    bill is drafted on the day it is expected; one still a draft some days
    later is missing, listed first.
--}}
<x-ui.page-header :eyebrow="__('Finance')" :title="__('Recurring costs')"
    :description="__('What the platform expects to pay every month or year. Each bill is drafted on the day it is expected, to be checked against the real one and recorded.')">
    @admincan('billing.manage')
    <x-slot:actions>
        <x-ui.button :href="route('admin.finance.recurring.create')">{{ __('Add a recurring cost') }}</x-ui.button>
    </x-slot:actions>
    @endadmincan
</x-ui.page-header>

@if ($missing->isNotEmpty())
    <x-ui.card class="mb-6" :title="__('Missing bills')" :description="__('Expected more than :days days ago and still not entered. Record each when its bill comes, or void it if none will.', ['days' => $missingDays])" flush data-missing-bills>
        <x-ui.table :primary="[0, 1, 2]">
            <x-slot:head>
                <x-ui.th>{{ __('Expected') }}</x-ui.th>
                <x-ui.th>{{ __('Supplier') }}</x-ui.th>
                <x-ui.th align="right">{{ __('Amount') }}</x-ui.th>
            </x-slot:head>
            @foreach ($missing as $expense)
                <tr class="hover:bg-sunken">
                    <td class="px-6 py-3 text-sm"><a href="{{ route('admin.finance.expenses.show', $expense->id) }}" class="font-semibold text-accent hover:underline">{{ $expense->expense_date->toDateString() }}</a>
                        <span class="block text-xs text-muted">{{ $expense->description }}</span></td>
                    <td class="px-6 py-3 text-sm text-fg">{{ $expense->supplier?->name }}</td>
                    <td class="px-6 py-3 text-right text-sm"><x-ui.amount :value="$expense->gross" :currency="$expense->currency" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
@endif

<x-ui.list-filters :list="$list" />

<div class="overflow-hidden rounded-card border border-line bg-surface shadow-card">
    <x-ui.table :primary="[0, 2, 3]">
        <x-slot:head>
            <x-ui.th>{{ __('Cost') }}</x-ui.th>
            <x-ui.th>{{ __('Supplier') }}</x-ui.th>
            <x-ui.th :list="$list" sort="next">{{ __('Next bill') }}</x-ui.th>
            <x-ui.th align="right" :list="$list" sort="amount">{{ __('Expected') }}</x-ui.th>
        </x-slot:head>
        @forelse ($recurring as $cost)
            <tr class="hover:bg-sunken">
                <td class="px-6 py-4 text-sm">
                    <a href="{{ route('admin.finance.recurring.show', $cost->id) }}" class="font-semibold text-accent hover:underline">{{ $cost->description }}</a>
                    <span class="block text-xs text-muted">{{ $frequencies[$cost->frequency] ?? $cost->frequency }}</span>
                    @unless ($cost->active)
                        <x-ui.badge state="paused" :label="__('Paused')" />
                    @endunless
                </td>
                <td class="px-6 py-4 text-sm text-fg">{{ $cost->supplier?->name }}</td>
                <td class="px-6 py-4 text-sm text-muted">{{ $cost->next_due_on->toDateString() }}</td>
                <td class="px-6 py-4 text-right text-sm"><x-ui.amount :value="(float) $cost->net + (float) $cost->vat" :currency="$cost->currency" /></td>
            </tr>
        @empty
            <x-ui.empty :columns="4">{{ __('No recurring cost matches.') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

{{ $recurring->links() }}
@endsection
