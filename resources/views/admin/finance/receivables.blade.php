@extends('layouts.admin')

@section('title', __('Receivables'))

@section('content')
<x-ui.page-header :title="__('Receivables')" :description="__('Every invoice the platform issued its customers, and what is still owed by how long past due.')" />

@forelse ($ageing as $currency => $table)
    <x-ui.card class="mb-6" :title="__('Owed by age (:currency)', ['currency' => $currency])" flush>
        @include('portal.partials.billing-ageing', ['table' => $table, 'headings' => $headings, 'currency' => $currency, 'customers' => true])
    </x-ui.card>
@empty
    <x-ui.alert tone="success" class="mb-6">{{ __('Nothing is owed.') }}</x-ui.alert>
@endforelse

<x-ui.list-filters :list="$list" />

<div class="bg-surface rounded-card border border-line shadow-card overflow-hidden">
    <x-ui.table :primary="[0, 1, 4]">
        <x-slot:head>
            <x-ui.th :list="$list" sort="issued">{{ __('Issued') }}</x-ui.th>
            <x-ui.th>{{ __('Customer') }}</x-ui.th>
            <x-ui.th :list="$list" sort="due">{{ __('Due') }}</x-ui.th>
            <x-ui.th>{{ __('Status') }}</x-ui.th>
            <x-ui.th align="right" :list="$list" sort="total">{{ __('Total') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Balance') }}</x-ui.th>
        </x-slot:head>
        @forelse ($receivables as $receivable)
            <tr class="hover:bg-sunken">
                <td class="px-6 py-4 text-sm text-fg">{{ $receivable->issue_date->toDateString() }}
                    <span class="block text-xs text-muted">{{ $receivable->description }}</span>
                </td>
                <td class="px-6 py-4 text-sm"><a href="{{ route('admin.billing.show', $receivable->org_id) }}" class="text-accent hover:underline">{{ $receivable->organization?->name }}</a></td>
                <td class="px-6 py-4 text-sm text-muted">{{ $receivable->due_date->toDateString() }}</td>
                <td class="px-6 py-4"><x-ui.badge :state="$receivable->status->value" :label="$receivable->status->label()" /></td>
                <td class="px-6 py-4 text-right text-sm text-fg"><x-ui.amount :value="$receivable->total" :currency="$receivable->currency" /></td>
                <td class="px-6 py-4 text-right text-sm text-fg"><x-ui.amount :value="$receivable->balance()" :currency="$receivable->currency" /></td>
            </tr>
        @empty
            <x-ui.empty :columns="6">{{ __('No invoice matches.') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

{{ $receivables->links() }}
@endsection
