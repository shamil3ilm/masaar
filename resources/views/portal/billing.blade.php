@extends('layouts.portal')

@section('title', 'Plan and billing')

@section('content')
<div class="mb-6">
    <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Plan and billing</h2>
    <p class="text-gray-500 dark:text-gray-400">Your plan, how much of it this month has used, and what you have paid. To renew or change plan, open a ticket under Support.</p>
</div>

<div class="mb-6 grid grid-cols-1 gap-6 md:grid-cols-3">
    <div class="rounded-lg bg-white dark:bg-gray-900 p-6 shadow">
        <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Plan</p>
        <p class="mt-1 text-lg font-semibold text-gray-800 dark:text-gray-100">{{ $licence?->tier->getDisplayName() ?? 'None yet' }}</p>
        @if ($licence)
            <x-ui.badge :state="$licence->status->value" />
        @endif
    </div>
    <div class="rounded-lg bg-white dark:bg-gray-900 p-6 shadow">
        <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Runs to</p>
        <p class="mt-1 text-lg font-semibold text-gray-800 dark:text-gray-100">{{ $licence?->expires_at?->toDateString() ?? 'No end date' }}</p>
        @if ($licence?->expires_at && $licence->expires_at->isFuture() && $licence->expires_at->diffInDays(now(), true) <= 14)
            <p class="mt-1 text-sm text-amber-700 dark:text-amber-300">Ends in {{ (int) ceil($licence->expires_at->diffInDays(now(), true)) }} days. Renew to keep issuing.</p>
        @endif
    </div>
    <div class="rounded-lg bg-white dark:bg-gray-900 p-6 shadow">
        <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Invoices this month</p>
        <p class="mt-1 text-lg font-semibold text-gray-800 dark:text-gray-100">{{ $usage['used'] }}{{ $usage['limit'] ? ' of '.$usage['limit'] : '' }}</p>
    </div>
</div>

<div class="bg-white dark:bg-gray-900 rounded-lg shadow overflow-hidden">
    <div class="p-6 border-b">
        <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">Payments</h3>
    </div>
    <x-ui.table>
        <x-slot:head>
            <x-ui.th>Received</x-ui.th>
            <x-ui.th>Plan</x-ui.th>
            <x-ui.th>Period</x-ui.th>
            <x-ui.th align="right">Total</x-ui.th>
            <x-ui.th>Invoice</x-ui.th>
        </x-slot:head>
        @forelse ($payments as $payment)
        <tr class="hover:bg-gray-50 dark:hover:bg-gray-950">
            <td class="px-6 py-4 text-sm text-gray-800 dark:text-gray-100">{{ $payment->paid_at->toDateString() }}</td>
            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ ucfirst($payment->tier) }}</td>
            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ $payment->period_start->toDateString() }} to {{ $payment->period_end->toDateString() }}</td>
            <td class="px-6 py-4 text-right text-sm tabular-nums text-gray-800 dark:text-gray-100">{{ number_format((float) $payment->total, 2) }} {{ $payment->currency }}</td>
            <td class="px-6 py-4 text-sm">
                @if ($payment->invoice_id)
                    <a href="{{ route('portal.billing.invoice', $payment->id) }}" class="text-teal-700 dark:text-teal-300 hover:underline">Download PDF</a>
                @else
                    <span class="text-gray-500 dark:text-gray-400">Being issued</span>
                @endif
            </td>
        </tr>
        @empty
        <x-ui.empty :columns="5">No payments yet</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>
@endsection
