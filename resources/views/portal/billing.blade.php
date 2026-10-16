@extends('layouts.portal')

@section('title', __('Plan and billing'))

@php
    $plans = [
        'starter' => __('Starter'),
        'professional' => __('Professional'),
        'enterprise' => __('Enterprise'),
        'unlimited' => __('Unlimited'),
    ];
@endphp

@section('content')
<div class="mb-6">
    <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ __('Plan and billing') }}</h2>
    <p class="text-gray-500 dark:text-gray-400">{{ __('Your plan, how much of it this month has used, and what you have paid. To renew or change plan, open a ticket under Support.') }}</p>
</div>

<div class="mb-6 grid grid-cols-1 gap-6 md:grid-cols-3">
    <div class="rounded-lg bg-white dark:bg-gray-900 p-6 shadow">
        <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">{{ __('Plan') }}</p>
        <p class="mt-1 text-lg font-semibold text-gray-800 dark:text-gray-100">{{ $licence ? ($plans[$licence->tier->value] ?? $licence->tier->getDisplayName()) : __('None yet') }}</p>
        @if ($licence)
            <x-ui.badge :state="$licence->status->value" />
        @endif
    </div>
    <div class="rounded-lg bg-white dark:bg-gray-900 p-6 shadow">
        <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">{{ __('Runs to') }}</p>
        <p class="mt-1 text-lg font-semibold text-gray-800 dark:text-gray-100"><span dir="ltr">{{ $licence?->expires_at?->toDateString() ?? __('No end date') }}</span></p>
        @if ($licence?->expires_at && $licence->expires_at->isFuture() && $licence->expires_at->diffInDays(now(), true) <= 14)
            <p class="mt-1 text-sm text-amber-700 dark:text-amber-300">{{ __('Ends in :days days. Renew to keep issuing.', ['days' => (int) ceil($licence->expires_at->diffInDays(now(), true))]) }}</p>
        @endif
    </div>
    <div class="rounded-lg bg-white dark:bg-gray-900 p-6 shadow">
        <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">{{ __('Invoices this month') }}</p>
        <p class="mt-1 text-lg font-semibold text-gray-800 dark:text-gray-100">{{ $usage['limit'] ? __(':used of :limit', ['used' => $usage['used'], 'limit' => $usage['limit']]) : $usage['used'] }}</p>
    </div>
</div>

<div class="bg-white dark:bg-gray-900 rounded-lg shadow overflow-hidden">
    <div class="p-6 border-b">
        <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ __('Payments') }}</h3>
    </div>
    <x-ui.table>
        <x-slot:head>
            <x-ui.th>{{ __('Received') }}</x-ui.th>
            <x-ui.th>{{ __('Plan') }}</x-ui.th>
            <x-ui.th>{{ __('Period') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Total') }}</x-ui.th>
            <x-ui.th>{{ __('Invoice') }}</x-ui.th>
        </x-slot:head>
        @forelse ($payments as $payment)
        <tr class="hover:bg-gray-50 dark:hover:bg-gray-950">
            <td class="px-6 py-4 text-sm text-gray-800 dark:text-gray-100"><span dir="ltr">{{ $payment->paid_at->toDateString() }}</span></td>
            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ $plans[$payment->tier] ?? ucfirst($payment->tier) }}</td>
            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ __(':from to :to', ['from' => $payment->period_start->toDateString(), 'to' => $payment->period_end->toDateString()]) }}</td>
            <td class="px-6 py-4 text-end text-sm tabular-nums text-gray-800 dark:text-gray-100"><span dir="ltr">{{ number_format((float) $payment->total, 2) }} {{ $payment->currency }}</span></td>
            <td class="px-6 py-4 text-sm">
                @if ($payment->invoice_id)
                    <a href="{{ route('portal.billing.invoice', $payment->id) }}" class="text-teal-700 dark:text-teal-300 hover:underline">{{ __('Download PDF') }}</a>
                @else
                    <span class="text-gray-500 dark:text-gray-400">{{ __('Being issued') }}</span>
                @endif
            </td>
        </tr>
        @empty
        <x-ui.empty :columns="5">{{ __('No payments yet') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>
@endsection
