@extends('layouts.portal')

@section('title', __('Statement of account'))

{{--
    A customer's statement for a period, in one currency (CustomerStatements).

    @param \App\Domains\Invoice\Models\Customer $customer
    @param array<string, mixed> $statement
    @param \Carbon\CarbonImmutable $from
    @param \Carbon\CarbonImmutable $to
    @param bool $partial  whether branches the person does not see are left out
    @param \Illuminate\Support\Collection $receipts  money on account, each with what is left (CustomerPayments::receipts)
    @param \Illuminate\Support\Collection $openInvoices  the customer's invoices still owed, which it can pay
    @param array<string, string> $methods
    @param bool $records
    @param \Carbon\CarbonImmutable $today
    @param string $homeCurrency
--}}

@section('content')
@php($query = ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'currency' => $statement['currency']])
<x-ui.page-header :title="__('Statement of account')" :description="app()->getLocale() === 'ar' && $customer->name_ar ? $customer->name_ar : $customer->name">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('Books'), 'href' => route('portal.books.index')], ['label' => __('Receivables'), 'href' => route('portal.books.receivables')], ['label' => $customer->name]]" />
    </x-slot:breadcrumb>
    <x-slot:actions>
        <x-ui.button variant="secondary" :href="route('portal.books.statements.pdf', [$customer->id, ...$query])">{{ __('Download PDF') }}</x-ui.button>
    </x-slot:actions>
</x-ui.page-header>

@if ($partial)
    <x-ui.alert tone="info" class="mb-6">{{ __('This statement covers only the branches you see, not the customer\'s whole account.') }}</x-ui.alert>
@endif

<x-ui.section :title="__('Period')" collapse class="mb-6">
    <form method="GET" action="{{ route('portal.books.statements.show', $customer->id) }}" class="grid grid-cols-1 gap-4 sm:grid-cols-4">
        <x-ui.field :label="__('From')"><x-ui.date-input name="from" value="{{ $from->toDateString() }}" /></x-ui.field>
        <x-ui.field :label="__('To')"><x-ui.date-input name="to" value="{{ $to->toDateString() }}" /></x-ui.field>
        @if (count($statement['currencies']) > 1)
            <x-ui.field :label="__('Currency')">
                <x-ui.select name="currency">
                    @foreach ($statement['currencies'] as $code)
                        <option value="{{ $code }}" @selected($code === $statement['currency'])>{{ $code }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
        @endif
        <div class="flex items-end"><x-ui.button>{{ __('Show') }}</x-ui.button></div>
    </form>
</x-ui.section>

<div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
    <x-ui.stat :label="__('Opening balance')" :value="\App\Domains\Books\Services\Purchases::shown($statement['opening']).' '.$statement['currency']" />
    <x-ui.stat :label="__('Billed')" :value="\App\Domains\Books\Services\Purchases::shown($statement['debits'])" />
    <x-ui.stat :label="__('Paid and credited')" :value="\App\Domains\Books\Services\Purchases::shown($statement['credits'])" />
    <x-ui.stat :label="__('Closing balance')" :value="\App\Domains\Books\Services\Purchases::shown($statement['closing']).' '.$statement['currency']" />
</div>

<x-ui.card class="overflow-hidden" flush>
    <x-ui.table :primary="[1, 2, 5]">
        <x-slot:head>
            <x-ui.th>{{ __('Date') }}</x-ui.th>
            <x-ui.th>{{ __('Entry') }}</x-ui.th>
            <x-ui.th>{{ __('Reference') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Debit') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Credit') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Balance') }}</x-ui.th>
        </x-slot:head>
        @forelse ($statement['lines'] as $line)
            <tr>
                <td class="px-6 py-3 text-sm"><x-ui.date :value="$line['date']" /></td>
                <td class="px-6 py-3 text-sm text-fg">{{ $line['kind'] }}</td>
                <td class="px-6 py-3 text-sm text-muted" dir="ltr">{{ $line['reference'] }}</td>
                <td class="px-6 py-3 text-end text-sm"><x-ui.amount :value="$line['debit']" /></td>
                <td class="px-6 py-3 text-end text-sm"><x-ui.amount :value="$line['credit']" /></td>
                <td class="px-6 py-3 text-end text-sm font-semibold"><x-ui.amount :value="$line['balance']" /></td>
            </tr>
        @empty
            <x-ui.empty :columns="6">{{ __('Nothing was billed, paid or credited in this period.') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</x-ui.card>

{{-- Money the customer paid on account: what is left of each, to pay an invoice with or to pay back. --}}
<div id="on-account" class="mt-6" data-on-account>
    <x-ui.card :title="__('Money on account')" class="mb-6 overflow-hidden" flush>
        <x-ui.table :primary="[0, 2, 3]">
            <x-slot:head>
                <x-ui.th>{{ __('Received') }}</x-ui.th>
                <x-ui.th>{{ __('Reference') }}</x-ui.th>
                <x-ui.th align="right">{{ __('Amount') }}</x-ui.th>
                <x-ui.th align="right">{{ __('Left') }}</x-ui.th>
                <x-ui.th align="right">{{ __('Actions') }}</x-ui.th>
            </x-slot:head>
            @forelse ($receipts as $receipt)
                <tr>
                    <td class="px-6 py-3 text-sm"><x-ui.date :value="$receipt->received_on" /></td>
                    <td class="px-6 py-3 text-sm text-muted" dir="ltr">
                        {{ $receipt->reference ?? $receipt->method->label() }}
                        @foreach ($receipt->entries as $entry)
                            <span class="block text-xs">{{ $entry->kind === 'refund' ? __('Refund') : __('Reversed') }}: <x-ui.date :value="$entry->received_on" /> <x-ui.amount :value="$entry->amount" /></span>
                            @if ($records && $entry->kind === 'refund' && ! $entry->reversed)
                                <form method="POST" action="{{ route('portal.books.receipts.reverse', $entry->id) }}" class="inline-flex items-end gap-2"
                                      data-confirm-tone="danger" data-confirm-action="{{ __('Reverse') }}" data-confirm="{{ __('Reverse this refund?') }}"
                                      data-confirm-detail="{{ __('A reversal is recorded today and the money is on account again. The refund stays on record.') }}">
                                    @csrf
                                    <x-ui.input name="reason" maxlength="500" required aria-label="{{ __('Why it is reversed') }}" placeholder="{{ __('Why it is reversed') }}" />
                                    <x-ui.button size="sm" variant="secondary">{{ __('Reverse') }}</x-ui.button>
                                </form>
                            @endif
                        @endforeach
                    </td>
                    <td class="px-6 py-3 text-end text-sm"><x-ui.amount :value="$receipt->amount" :currency="$receipt->currency" /></td>
                    <td class="px-6 py-3 text-end text-sm font-semibold"><x-ui.amount :value="$receipt->left" :currency="$receipt->currency" /></td>
                    <td class="px-6 py-3 text-end text-sm">
                        @if ($records && $receipt->left > 0)
                            @php($payable = $openInvoices->where('currency', $receipt->currency))
                            @if ($payable->isNotEmpty())
                                <form method="POST" action="{{ route('portal.books.receipts.allocate', $receipt->id) }}" class="mb-2 inline-flex flex-wrap items-end gap-2">
                                    @csrf
                                    <x-ui.select name="invoice_id" aria-label="{{ __('Invoice') }}">
                                        @foreach ($payable as $row)
                                            <option value="{{ $row['invoice']->id }}">{{ $row['number'] }} ({{ \App\Domains\Books\Services\Purchases::shown($row['balance']) }})</option>
                                        @endforeach
                                    </x-ui.select>
                                    <x-ui.input name="amount" inputmode="decimal" dir="ltr" required aria-label="{{ __('Amount') }}" value="{{ \App\Domains\Books\Services\Purchases::money(min($receipt->left, (float) $payable->first()['balance'])) }}" />
                                    <input type="hidden" name="paid_on" value="{{ $today->toDateString() }}">
                                    <x-ui.button size="sm">{{ __('Pay invoice') }}</x-ui.button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('portal.books.receipts.refund', $receipt->id) }}" class="inline-flex flex-wrap items-end gap-2"
                                  data-confirm="{{ __('Record a refund of money on account?') }}" data-confirm-action="{{ __('Record refund') }}"
                                  data-confirm-detail="{{ __('The money paid back to the customer is recorded against what is left; a refund recorded in error is reversed, not deleted.') }}">
                                @csrf
                                <x-ui.input name="amount" inputmode="decimal" dir="ltr" required aria-label="{{ __('Amount') }}" value="{{ \App\Domains\Books\Services\Purchases::money($receipt->left) }}" />
                                <x-ui.select name="method" aria-label="{{ __('Method') }}">
                                    @foreach ($methods as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </x-ui.select>
                                <input type="hidden" name="paid_on" value="{{ $today->toDateString() }}">
                                <x-ui.button size="sm" variant="secondary">{{ __('Record refund') }}</x-ui.button>
                            </form>
                        @endif
                        @if ($records && ! $receipt->reversed && abs($receipt->left - (float) $receipt->amount) < 0.005)
                            <form method="POST" action="{{ route('portal.books.receipts.reverse', $receipt->id) }}" class="mt-2 inline-flex items-end gap-2"
                                  data-confirm-tone="danger" data-confirm-action="{{ __('Reverse') }}" data-confirm="{{ __('Reverse this money on account?') }}"
                                  data-confirm-detail="{{ __('A reversal for the same amount is recorded today. The receipt stays on record.') }}">
                                @csrf
                                <x-ui.input name="reason" maxlength="500" required aria-label="{{ __('Why it is reversed') }}" placeholder="{{ __('Why it is reversed') }}" />
                                <x-ui.button size="sm" variant="secondary">{{ __('Reverse') }}</x-ui.button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <x-ui.empty :columns="5">{{ __('No money is held on account for this customer.') }}</x-ui.empty>
            @endforelse
        </x-ui.table>
    </x-ui.card>

    @if ($records)
        <x-ui.section :title="__('Record money received on account')" :description="__('Money the customer paid before an invoice: it pays the customer\'s invoices later, or is paid back.')" collapse>
            <form method="POST" action="{{ route('portal.books.receipts.store', $customer->id) }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                @csrf
                <x-ui.field :label="__('Amount')" error="amount">
                    <x-ui.input name="amount" inputmode="decimal" dir="ltr" required value="{{ old('amount') }}" />
                </x-ui.field>
                <x-ui.field :label="__('Currency')" error="currency">
                    <x-ui.input name="currency" maxlength="3" dir="ltr" required value="{{ old('currency', $statement['currency'] ?: $homeCurrency) }}" />
                </x-ui.field>
                <x-ui.field :label="__('Received on')" error="received_on">
                    <x-ui.date-input name="received_on" required value="{{ old('received_on', $today->toDateString()) }}" />
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
                <x-ui.field :label="__('Note')" error="notes" optional>
                    <x-ui.input name="notes" maxlength="500" value="{{ old('notes') }}" />
                </x-ui.field>
                <div><x-ui.button>{{ __('Record money on account') }}</x-ui.button></div>
            </form>
        </x-ui.section>
    @endif
</div>
@endsection
