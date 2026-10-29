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
                <td class="px-6 py-3 text-sm text-muted">{{ $payment->isReversal() ? __('Payment reversed') : ((float) $payment->amount < 0 ? __('Refund').' ('.$payment->method->label().')' : $payment->method->label()) }}</td>
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

@if ($reliefs->isNotEmpty())
    <x-ui.card :title="__('Bad-debt relief')" class="mb-6 overflow-hidden" flush>
        <x-ui.table :primary="[0, 1, 2]">
            <x-slot:head>
                <x-ui.th>{{ __('Date') }}</x-ui.th>
                <x-ui.th>{{ __('Entry') }}</x-ui.th>
                <x-ui.th align="right">{{ __('Amount') }}</x-ui.th>
            </x-slot:head>
            @foreach ($reliefs as $relief)
                <tr>
                    <td class="px-6 py-3 text-sm"><x-ui.date :value="$relief->happened_on" /></td>
                    <td class="px-6 py-3 text-sm text-muted">
                        {{ match ($relief->kind) { 'relief' => __('Written off: output tax taken back'), 'recovery_reversed' => __('Payment reversed: output tax taken back again'), default => __('Paid after the write-off: output tax due again') } }}
                        {{-- The reversed payment's tax invoice was issued: only a credit note against it takes it back. --}}
                        @if ($relief->kind === 'recovery_reversed' && $relief->by_credit_note)
                            @php($issued = ($recoveryInvoices ?? [])[(string) $relief->relief_id] ?? null)
                            <a href="{{ $issued ? route('portal.invoices.show', $issued->id) : '#' }}" class="block text-warning hover:underline" data-recovery-credit-note>
                                {{ __('Tax invoice :number was issued for the reversed payment: issue a credit note against it. The credit note takes its tax back on the VAT return.', ['number' => $issued?->invoice_number ?? '-']) }}
                            </a>
                        @endif
                        {{-- KSA VAT Implementing Regulations Art 40(9): a new tax invoice for the amount received. --}}
                        @if ($relief->kind === 'recovery' && $badDebtRules?->newInvoiceOnPayment)
                            @php($drafted = ($recoveryInvoices ?? [])[(string) $relief->id] ?? null)
                            @if ($drafted)
                                <a href="{{ route('portal.invoices.show', $drafted->id) }}" class="block text-accent hover:underline" data-recovery-invoice>
                                    {{ $drafted->isEditable() ? __('New tax invoice for the amount received: a draft to check and issue') : __('New tax invoice :number for the amount received', ['number' => $drafted->invoice_number]) }}
                                </a>
                            @else
                                <span class="block text-warning">{{ __('A new tax invoice for the amount received is needed and could not be drafted: write one on the new-invoice form.') }}</span>
                            @endif
                        @endif
                    </td>
                    <td class="px-6 py-3 text-end text-sm"><x-ui.amount :value="$relief->amount" :currency="$invoice->currency" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
@endif

@if ($records && $badDebtRules && $owed > 0 && $writtenOff <= 0)
    <x-ui.section :title="__('Claim bad-debt relief')" :description="__('Takes the output tax on what is unpaid back off the return, once :rule is met. Paid after all, the tax is due again.', ['rule' => $badDebtRules->citation])" collapse class="mb-6">
        <form method="POST" action="{{ route('portal.books.invoices.bad-debt', $invoice->id) }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2"
              data-confirm="{{ __('Claim bad-debt relief on :number?', ['number' => $invoice->invoice_number]) }}"
              data-confirm-detail="{{ __('The output tax on what is unpaid comes off the VAT return of the period it is claimed in.') }}"
              data-confirm-action="{{ __('Claim relief') }}">
            @csrf
            <x-ui.field :label="__('Written off in the books on')" error="written_off_on" :hint="__('At least :months months after the supply.', ['months' => $badDebtRules->months])">
                <x-ui.date-input name="written_off_on" required value="{{ old('written_off_on', $today->toDateString()) }}" />
            </x-ui.field>
            @if ($badDebtRules->needsNotice)
                <x-ui.field :label="__('Customer told of the write-off on')" error="notified_on">
                    <x-ui.date-input name="notified_on" required value="{{ old('notified_on') }}" />
                </x-ui.field>
            @endif
            @if ($badDebtRules->needsCertificate)
                <x-ui.field :label="__('Certified accountant\'s certificate reference')" error="certificate">
                    <x-ui.input name="certificate" maxlength="200" required value="{{ old('certificate') }}" />
                </x-ui.field>
            @endif
            @if ($badDebtRules->evidenceAbove !== null)
                <x-ui.field :label="__('Evidence that formal recovery failed')" error="evidence" :hint="__('Needed where the customer owes more than :limit: a judgment, bankruptcy or other court order.', ['limit' => number_format($badDebtRules->evidenceAbove)])" optional>
                    <x-ui.input name="evidence" maxlength="200" value="{{ old('evidence') }}" />
                </x-ui.field>
            @endif
            @if ($badDebtRules->needsUnrelated)
                <div class="sm:col-span-2">
                    <x-ui.checkbox name="unrelated" value="1" :checked="(bool) old('unrelated')">{{ __('The customer is not a related person') }}</x-ui.checkbox>
                </div>
            @endif
            <div><x-ui.button variant="secondary">{{ __('Claim relief') }}</x-ui.button></div>
        </form>
    </x-ui.section>
@endif

@if ($records && ($owed > 0 || $writtenOff > 0))
    <x-ui.card :title="__('Record a payment')" class="max-w-3xl">
        <form method="POST" action="{{ route('portal.books.invoices.pay', $invoice->id) }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @csrf
            <x-ui.field :label="__('Amount (:currency)', ['currency' => $invoice->currency])" error="amount" :hint="__('All of what is owed, or part of it.')">
                <x-ui.input name="amount" inputmode="decimal" dir="ltr" required value="{{ old('amount', \App\Domains\Books\Services\Purchases::money($owed + $writtenOff)) }}" />
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

{{-- Paid and then credited, or a credit note on nothing owed: the credit is the customer's to be paid back. --}}
@if ($records && $owed < 0)
    <x-ui.card :title="__('Pay back the credit')" class="max-w-3xl" data-refund>
        <form method="POST" action="{{ route('portal.books.invoices.refund', $invoice->id) }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2"
              data-confirm="{{ __('Record a refund on :number?', ['number' => $invoice->invoice_number]) }}"
              data-confirm-detail="{{ __('The money paid back to the customer is recorded against the credit; a refund recorded in error is reversed, not deleted.') }}"
              data-confirm-action="{{ __('Record refund') }}">
            @csrf
            <x-ui.field :label="__('Amount (:currency)', ['currency' => $invoice->currency])" error="amount" :hint="__('Up to the credit of :credit.', ['credit' => \App\Domains\Books\Services\Purchases::shown(-$owed)])">
                <x-ui.input name="amount" inputmode="decimal" dir="ltr" required value="{{ old('amount', \App\Domains\Books\Services\Purchases::money(-$owed)) }}" />
            </x-ui.field>
            <x-ui.field :label="__('Paid back on')" error="paid_on">
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
            <div><x-ui.button variant="secondary">{{ __('Record refund') }}</x-ui.button></div>
        </form>
    </x-ui.card>
@endif
@endsection
