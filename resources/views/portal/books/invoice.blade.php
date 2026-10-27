@extends('layouts.portal')

@section('title', $invoice->invoice_number)

{{--
    One issued invoice's payments: what it owes now, each payment and
    reversal, and the form to record a payment.

    @param \App\Domains\Invoice\Models\Invoice $invoice
    @param \Illuminate\Support\Collection $payments
    @param list<string> $reversed  ids of payments already taken back
    @param float $owed
    @param array<string, string> $methods
    @param bool $records
    @param \Carbon\CarbonImmutable $today
--}}

@section('content')
<x-ui.page-header :title="__('Payments on :number', ['number' => $invoice->invoice_number])" :description="$invoice->buyer_name">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('Books'), 'href' => route('portal.books.index')], ['label' => __('Receivables'), 'href' => route('portal.books.receivables')], ['label' => (string) $invoice->invoice_number]]" />
    </x-slot:breadcrumb>
    <x-slot:actions>
        <x-ui.button variant="secondary" :href="route('portal.invoices.show', $invoice->id)">{{ __('Open the invoice') }}</x-ui.button>
    </x-slot:actions>
</x-ui.page-header>

<div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-3">
    <x-ui.stat :label="__('To pay (:currency)', ['currency' => $invoice->currency])" :value="\App\Domains\Books\Services\Purchases::shown($invoice->payable())" />
    <x-ui.stat :label="__('Owed now (:currency)', ['currency' => $invoice->currency])" :value="\App\Domains\Books\Services\Purchases::shown($owed)" />
    <x-ui.stat :label="__('Issued')" :value="\Carbon\CarbonImmutable::parse($invoice->issue_date)->toDateString()" />
</div>

<x-ui.card class="mb-6 overflow-hidden" flush>
    <x-ui.table :primary="[0, 1, 2]">
        <x-slot:head>
            <x-ui.th>{{ __('Date') }}</x-ui.th>
            <x-ui.th>{{ __('Method') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Amount') }}</x-ui.th>
            <x-ui.th>{{ __('Reference') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Actions') }}</x-ui.th>
        </x-slot:head>
        @forelse ($payments as $payment)
            <tr>
                <td class="px-6 py-3 text-sm"><x-ui.date :value="$payment->paid_on" /></td>
                <td class="px-6 py-3 text-sm text-muted">{{ $payment->isReversal() ? __('Payment reversed') : $payment->method->label() }}</td>
                <td class="px-6 py-3 text-end text-sm"><x-ui.amount :value="$payment->amount" :currency="$payment->currency" /></td>
                <td class="px-6 py-3 text-sm text-muted">{{ $payment->reference ?? $payment->notes ?? '-' }}</td>
                <td class="px-6 py-3 text-end text-sm">
                    @if ($records && ! $payment->isReversal() && ! in_array((string) $payment->id, $reversed, true))
                        <form method="POST" action="{{ route('portal.books.payments.reverse', $payment->id) }}" class="inline-flex items-end gap-2"
                              data-confirm-tone="danger" data-confirm-action="{{ __('Reverse payment') }}"
                              data-confirm="{{ __('Reverse this payment?') }}"
                              data-confirm-detail="{{ __('A reversal for the same amount is recorded today, and the invoice is owed again. The payment stays on record.') }}">
                            @csrf
                            <x-ui.input name="reason" maxlength="500" required aria-label="{{ __('Why it is reversed') }}" placeholder="{{ __('Why it is reversed') }}" />
                            <x-ui.button size="sm" variant="secondary">{{ __('Reverse') }}</x-ui.button>
                        </form>
                    @endif
                </td>
            </tr>
        @empty
            <x-ui.empty :columns="5">{{ __('No payment is recorded on this invoice yet.') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</x-ui.card>

@if ($records && $owed > 0)
    <x-ui.card :title="__('Record a payment')" class="max-w-3xl">
        <form method="POST" action="{{ route('portal.books.invoices.pay', $invoice->id) }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @csrf
            <x-ui.field :label="__('Amount (:currency)', ['currency' => $invoice->currency])" error="amount" :hint="__('All of what is owed, or part of it.')">
                <x-ui.input name="amount" inputmode="decimal" dir="ltr" required value="{{ old('amount', \App\Domains\Books\Services\Purchases::money($owed)) }}" />
            </x-ui.field>
            <x-ui.field :label="__('Paid on')" error="paid_on">
                <x-ui.date-input name="paid_on" required value="{{ old('paid_on', $today->toDateString()) }}" />
            </x-ui.field>
            <x-ui.field :label="__('Method')" error="method">
                <x-ui.select name="method">
                    @foreach ($methods as $value => $label)
                        <option value="{{ $value }}" @selected(old('method') === $value)>{{ $label }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <x-ui.field :label="__('Reference')" error="reference" optional>
                <x-ui.input name="reference" maxlength="100" value="{{ old('reference') }}" />
            </x-ui.field>
            <x-ui.field :label="__('Note')" error="notes" optional class="sm:col-span-2">
                <x-ui.input name="notes" maxlength="500" value="{{ old('notes') }}" />
            </x-ui.field>
            <div><x-ui.button>{{ __('Record payment') }}</x-ui.button></div>
        </form>
    </x-ui.card>
@endif
@endsection
