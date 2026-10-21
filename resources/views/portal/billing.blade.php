@extends('layouts.portal')

@section('title', __('Plan and billing'))

@php
    $plans = \App\Domains\Billing\Services\PaymentHistory::plans();
@endphp

@section('content')
<x-ui.page-header :title="__('Plan and billing')" :description="__('Your plan, how much of it this month has used, and what you have paid. To renew or change plan, open a request under Requests.')" />

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

@if (in_array($account['standing']->stage, ['restricted', 'frozen'], true))
    <x-ui.alert tone="danger" class="mb-6" :title="__('Account frozen: payment overdue.')">{{ __('Nothing new can be issued and nobody new added until the overdue invoices below are paid. You can still read and export all your records, and what you already issued keeps being filed.') }}</x-ui.alert>
@elseif (in_array($account['standing']->stage, ['reminders', 'grace'], true))
    <x-ui.alert tone="warning" class="mb-6">{{ __('An invoice from us is :days days past due. Please pay it to keep issuing without interruption.', ['days' => $account['standing']->daysOverdue]) }}</x-ui.alert>
@endif
@php($owed = $account['receivables']->filter(fn ($receivable) => $receivable->status->outstanding())->sum(fn ($receivable) => (float) $receivable->balance()))
<div class="mb-3 flex flex-wrap items-center justify-between gap-3">
    <h2 class="text-lg font-semibold text-fg">{{ __('Invoices') }}</h2>
    <x-ui.button variant="secondary" :href="route('portal.billing.statement')">{{ __('Statement') }}</x-ui.button>
</div>
<p class="mb-3 text-sm text-muted">
    {{ __('Owed') }} <x-ui.amount :value="$owed" :currency="$account['currency']" />
    @if ((float) $account['credit'] > 0)
        · {{ __('To your credit, taken by your next invoice') }} <x-ui.amount :value="$account['credit']" :currency="$account['currency']" />
    @endif
    @if ($account['free_until'] && $account['free_until']->isFuture())
        · {{ __('Free until :date.', ['date' => $account['free_until']->toDateString()]) }}
    @endif
</p>

<div class="mb-8 bg-surface rounded-card border border-line shadow-card overflow-hidden">
    <x-ui.table :primary="[0, 3, 5]">
        <x-slot:head>
            <x-ui.th>{{ __('Invoice') }}</x-ui.th>
            <x-ui.th>{{ __('Issued') }}</x-ui.th>
            <x-ui.th>{{ __('Due') }}</x-ui.th>
            <x-ui.th>{{ __('Status') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Total') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Balance') }}</x-ui.th>
            <x-ui.th>{{ __('PDF') }}</x-ui.th>
        </x-slot:head>
        @forelse ($account['receivables'] as $receivable)
        <tr class="hover:bg-sunken">
            <td class="px-6 py-4 text-sm font-medium text-fg"><span dir="ltr">{{ $account['numbers'][$receivable->id] ?? '' }}</span>
                <span class="block text-xs font-normal text-muted">{{ $receivable->description }}</span>
            </td>
            <td class="px-6 py-4 text-sm text-muted"><span dir="ltr">{{ $receivable->issue_date->toDateString() }}</span></td>
            <td class="px-6 py-4 text-sm text-muted"><span dir="ltr">{{ $receivable->due_date->toDateString() }}</span></td>
            <td class="px-6 py-4"><x-ui.badge :state="$receivable->status->value" :label="$receivable->status->label()" /></td>
            <td class="px-6 py-4 text-end text-sm tabular-nums text-fg"><x-ui.amount :value="$receivable->total" :currency="$receivable->currency" />
                @if ($receivable->exchange_rate !== null)
                    <span class="block text-xs text-muted">{{ __('VAT') }} <x-ui.amount :value="$receivable->vat_amount_tax" :currency="$receivable->tax_currency" /></span>
                @endif
            </td>
            <td class="px-6 py-4 text-end text-sm tabular-nums text-fg"><x-ui.amount :value="$receivable->balance()" :currency="$receivable->currency" /></td>
            <td class="px-6 py-4 text-sm">
                <a href="{{ route('portal.billing.receivables.pdf', $receivable->id) }}" class="text-accent hover:underline">{{ __('Download PDF') }}</a>
                @if ($cards && $receivable->status->outstanding())
                    <form method="POST" action="{{ route('portal.billing.pay', $receivable->id) }}" class="mt-2">
                        @csrf
                        <x-ui.button size="sm">{{ __('Pay by card') }}</x-ui.button>
                    </form>
                @endif
            </td>
        </tr>
        @empty
        <x-ui.empty :columns="7">{{ __('No invoice from us yet.') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

@if ($account['accounts'] !== [])
    <x-ui.card class="mb-8" :title="__('Paying by bank transfer')" :description="__('Quote the invoice number as the transfer reference.')">
        <ul class="space-y-2 text-sm">
            @foreach ($account['accounts'] as $bank)
                <li><span class="font-medium text-fg">{{ $bank['bank_name'] }}</span> · {{ $bank['account_name'] }} · <span class="font-mono" dir="ltr">{{ \App\Domains\Billing\Settings\Iban::format($bank['iban']) }}</span>@if ($bank['swift'] !== '') · <span class="font-mono" dir="ltr">{{ $bank['swift'] }}</span>@endif</li>
            @endforeach
        </ul>
    </x-ui.card>
@endif

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
            <td class="px-6 py-4 text-sm text-muted">{{ $payment->tier ? ($plans[$payment->tier] ?? ucfirst($payment->tier)) : $payment->method->label() }}</td>
            <td class="px-6 py-4 text-sm text-muted">@if ($payment->period_start){{ __(':from to :to', ['from' => "\u{2066}".$payment->period_start->toDateString()."\u{2069}", 'to' => "\u{2066}".$payment->period_end?->toDateString()."\u{2069}"]) }}@endif</td>
            <td class="px-6 py-4 text-end text-sm tabular-nums text-fg"><x-ui.amount :value="$payment->total" :currency="$payment->currency" /></td>
            <td class="px-6 py-4 text-sm">
                @if ($payment->invoice_id)
                    <a href="{{ route('portal.billing.invoice', $payment->id) }}" class="text-accent hover:underline">{{ __('Download PDF') }}</a>
                @elseif ($payment->tier)
                    <span class="text-muted">{{ __('Being issued') }}</span>
                @else
                    <span class="text-muted">-</span>
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
