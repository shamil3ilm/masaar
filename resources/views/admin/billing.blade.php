@extends('layouts.admin')

@section('title', 'Billing - '.$organization->name)

@section('content')
<div class="mb-6">
    <nav class="text-sm text-gray-500 dark:text-gray-400">
        <a href="{{ route('admin.organizations') }}" class="hover:text-teal-700 dark:hover:text-teal-300">Organizations</a>
        <span>/</span>
        <a href="{{ route('admin.organization.detail', $organization->id) }}" class="hover:text-teal-700 dark:hover:text-teal-300">{{ $organization->name }}</a>
        <span>/</span>
        <span>Billing</span>
    </nav>
    <h2 class="mt-2 text-2xl font-bold text-gray-800 dark:text-gray-100">Billing</h2>
    <p class="text-gray-500 dark:text-gray-400">Recording a payment renews the plan and issues the platform's invoice for it.</p>
</div>

@if ($billingOrganization === null)
    <div class="mb-6 rounded border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/40 px-4 py-3 text-sm text-amber-900 dark:text-amber-100">
        No billing organization is set (BILLING_ORGANIZATION_ID). Payments renew plans, and no invoice is issued for them until one is.
    </div>
@endif

<div class="mb-6 grid grid-cols-1 gap-6 md:grid-cols-3">
    <div class="rounded-lg bg-white dark:bg-gray-900 p-6 shadow">
        <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Plan</p>
        <p class="mt-1 text-lg font-semibold text-gray-800 dark:text-gray-100">{{ $licence?->tier->getDisplayName() ?? 'None' }}</p>
        @if ($licence)
            <x-ui.badge :state="$licence->status->value" />
        @endif
    </div>
    <div class="rounded-lg bg-white dark:bg-gray-900 p-6 shadow">
        <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Runs to</p>
        <p class="mt-1 text-lg font-semibold text-gray-800 dark:text-gray-100">{{ $licence?->expires_at?->toDateString() ?? 'No end date' }}</p>
    </div>
    <div class="rounded-lg bg-white dark:bg-gray-900 p-6 shadow">
        <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Invoices this month</p>
        <p class="mt-1 text-lg font-semibold text-gray-800 dark:text-gray-100">{{ $usage['used'] }}{{ $usage['limit'] ? ' of '.$usage['limit'] : '' }}</p>
    </div>
</div>

@admincan('billing.manage')
<form method="POST" action="{{ route('admin.billing.record', $organization->id) }}" class="mb-6 rounded-lg bg-white dark:bg-gray-900 shadow">
    @csrf
    <div class="border-b p-4">
        <h3 class="font-semibold text-gray-800 dark:text-gray-100">Record a payment</h3>
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Monthly prices, excluding VAT:
            @foreach ($tiers as $tier)
                {{ $tier->getDisplayName() }} {{ number_format($prices[$tier->value]) }}{{ $loop->last ? '' : ',' }}
            @endforeach
            SAR.
        </p>
    </div>
    <div class="grid grid-cols-1 gap-4 p-4 sm:grid-cols-3">
        <x-ui.field label="Plan">
            <x-ui.select name="tier">
                @foreach ($tiers as $tier)
                    <option value="{{ $tier->value }}" @selected(old('tier', $licence?->tier->value) === $tier->value)>{{ $tier->getDisplayName() }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>
        <x-ui.field label="Months paid for">
            <x-ui.input type="number" name="months" min="1" max="36" required value="{{ old('months', 12) }}" />
        </x-ui.field>
        <x-ui.field label="Amount received, excluding VAT (SAR)">
            <x-ui.input type="number" name="amount" step="0.01" min="0" required value="{{ old('amount') }}" />
        </x-ui.field>
        <x-ui.field label="Method">
            <x-ui.select name="method">
                @foreach ($methods as $method)
                    <option value="{{ $method->value }}" @selected(old('method') === $method->value)>{{ $method->label() }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>
        <x-ui.field label="Reference (transfer or receipt number)">
            <x-ui.input type="text" name="reference" maxlength="100" value="{{ old('reference') }}" />
        </x-ui.field>
        <x-ui.field label="Received on">
            <x-ui.input type="date" name="paid_at" required value="{{ old('paid_at', now()->toDateString()) }}" />
        </x-ui.field>
        <div class="sm:col-span-3">
            <x-ui.field label="Notes (optional)">
                <x-ui.textarea name="notes" rows="2" maxlength="1000">{{ old('notes') }}</x-ui.textarea>
            </x-ui.field>
        </div>
    </div>
    <div class="border-t p-4">
        <x-ui.button>Record payment</x-ui.button>
    </div>
</form>
@endadmincan

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
            <x-ui.th>Method</x-ui.th>
            <x-ui.th>Invoice</x-ui.th>
        </x-slot:head>
        @forelse ($payments as $payment)
        <tr class="hover:bg-gray-50 dark:hover:bg-gray-950">
            <td class="px-6 py-4 text-sm text-gray-800 dark:text-gray-100">{{ $payment->paid_at->toDateString() }}</td>
            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ ucfirst($payment->tier) }}, {{ $payment->months }} mo</td>
            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ $payment->period_start->toDateString() }} to {{ $payment->period_end->toDateString() }}</td>
            <td class="px-6 py-4 text-right text-sm tabular-nums text-gray-800 dark:text-gray-100">{{ number_format((float) $payment->total, 2) }} {{ $payment->currency }}</td>
            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">
                {{ $payment->method->label() }}
                @if ($payment->reference)
                    <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $payment->reference }}</span>
                @endif
            </td>
            <td class="px-6 py-4 text-sm">
                @if ($payment->invoice_id)
                    <a href="{{ route('admin.billing.download', [$organization->id, $payment->id]) }}" class="text-teal-700 dark:text-teal-300 hover:underline">PDF</a>
                @else
                    <span class="block text-xs text-amber-700 dark:text-amber-300">{{ $payment->invoice_error ?? 'Not issued' }}</span>
                    @admincan('billing.manage')
                    <form method="POST" action="{{ route('admin.billing.invoice', [$organization->id, $payment->id]) }}" class="mt-1">
                        @csrf
                        <x-ui.button variant="outline">Issue invoice</x-ui.button>
                    </form>
                    @endadmincan
                @endif
            </td>
        </tr>
        @empty
        <x-ui.empty :columns="6">No payments recorded</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>
@endsection
