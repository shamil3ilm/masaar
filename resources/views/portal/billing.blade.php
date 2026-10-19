@extends('layouts.portal')

@section('title', __('Plan and billing'))

@php
    $plans = \App\Domains\Billing\Services\PaymentHistory::plans();
@endphp

@section('content')
<x-ui.page-header :title="__('Plan and billing')" :description="__('Your plan, how much of it this month has used, and what you have paid. To renew or change plan, open a ticket under Support.')" />

<div class="mb-6 grid grid-cols-1 gap-6 md:grid-cols-3">
    <div class="rounded-card bg-surface p-6 border border-line shadow-card">
        <p class="text-xs font-medium uppercase text-muted">{{ __('Plan') }}</p>
        <p class="mt-1 text-lg font-semibold text-fg">{{ $licence ? ($plans[$licence->tier->value] ?? $licence->tier->getDisplayName()) : __('None yet') }}</p>
        @if ($licence)
            <x-ui.badge :state="$licence->status->value" />
        @endif
    </div>
    <div class="rounded-card bg-surface p-6 border border-line shadow-card">
        <p class="text-xs font-medium uppercase text-muted">{{ __('Runs to') }}</p>
        <p class="mt-1 text-lg font-semibold text-fg"><span dir="ltr">{{ $licence?->expires_at?->toDateString() ?? __('No end date') }}</span></p>
        @if ($licence?->expires_at && $licence->expires_at->isFuture() && $licence->expires_at->diffInDays(now(), true) <= 14)
            <p class="mt-1 text-sm text-warning">{{ __('Ends in :days days. Renew to keep issuing.', ['days' => (int) ceil($licence->expires_at->diffInDays(now(), true))]) }}</p>
        @endif
    </div>
    <div class="rounded-card bg-surface p-6 border border-line shadow-card">
        <p class="text-xs font-medium uppercase text-muted">{{ __('Invoices this month') }}</p>
        <p class="mt-1 text-lg font-semibold text-fg">{{ $usage['limit'] ? __(':used of :limit', ['used' => $usage['used'], 'limit' => $usage['limit']]) : $usage['used'] }}</p>
    </div>
</div>

<h2 class="mb-3 text-lg font-semibold text-fg">{{ __('Payments') }}</h2>

<x-ui.list-filters :list="$list" />

<div class="bg-surface rounded-card border border-line shadow-card overflow-hidden">
    <x-ui.table :primary="[0, 3, 1]">
        <x-slot:head>
            <x-ui.th :list="$list" sort="date">{{ __('Received') }}</x-ui.th>
            <x-ui.th>{{ __('Plan') }}</x-ui.th>
            <x-ui.th>{{ __('Period') }}</x-ui.th>
            <x-ui.th :list="$list" sort="amount" align="right">{{ __('Total') }}</x-ui.th>
            <x-ui.th>{{ __('Invoice') }}</x-ui.th>
        </x-slot:head>
        @forelse ($payments as $payment)
        <tr class="hover:bg-sunken">
            <td class="px-6 py-4 text-sm text-fg"><span dir="ltr">{{ $payment->paid_at->toDateString() }}</span></td>
            <td class="px-6 py-4 text-sm text-muted">{{ $plans[$payment->tier] ?? ucfirst($payment->tier) }}</td>
            <td class="px-6 py-4 text-sm text-muted">{{ __(':from to :to', ['from' => "\u{2066}".$payment->period_start->toDateString()."\u{2069}", 'to' => "\u{2066}".$payment->period_end->toDateString()."\u{2069}"]) }}</td>
            <td class="px-6 py-4 text-end text-sm tabular-nums text-fg"><x-ui.amount :value="$payment->total" :currency="$payment->currency" /></td>
            <td class="px-6 py-4 text-sm">
                @if ($payment->invoice_id)
                    <a href="{{ route('portal.billing.invoice', $payment->id) }}" class="text-accent hover:underline">{{ __('Download PDF') }}</a>
                @else
                    <span class="text-muted">{{ __('Being issued') }}</span>
                @endif
            </td>
        </tr>
        @empty
        <x-ui.empty :columns="5">{{ $list->filtered() ? __('No payment matches that filter.') : __('No payments yet') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

{{ $payments->links() }}
@endsection
