@extends('layouts.portal')

@section('title', $purchase->supplier_invoice_number)

{{--
    One purchase: its totals in both currencies, its lines, the supplier's
    file, and voiding it.

    @param \App\Domains\Books\Models\PurchaseInvoice $purchase
    @param bool $records
--}}

@section('content')
<x-ui.page-header :title="$purchase->documentLabel().' '.$purchase->supplier_invoice_number" :description="$purchase->supplier?->name">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('Books'), 'href' => route('portal.books.index')], ['label' => __('Purchases'), 'href' => route('portal.books.purchases.index')], ['label' => $purchase->supplier_invoice_number]]" />
    </x-slot:breadcrumb>
    @if ($purchase->file_path)
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('portal.books.purchases.file', $purchase->id)">{{ __('Download the supplier\'s document') }}</x-ui.button>
        </x-slot:actions>
    @endif
</x-ui.page-header>

@if ($purchase->isVoid())
    <x-ui.alert tone="warning" class="mb-6">{{ __('Void, and left out of the VAT return: :reason', ['reason' => $purchase->void_reason]) }}</x-ui.alert>
@endif

<div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-5">
    <x-ui.stat :label="__('Date')" :value="$purchase->invoice_date->toDateString()" />
    <x-ui.stat :label="__('Received on')" :value="(string) $purchase->received_on?->toDateString()" />
    <x-ui.stat :label="__('Net (tax currency)')" :value="\App\Domains\Books\Services\Purchases::money($purchase->sign() * (float) $purchase->net_tax)" />
    <x-ui.stat :label="__('VAT charged (tax currency)')" :value="\App\Domains\Books\Services\Purchases::money($purchase->sign() * (float) $purchase->vat_tax)" />
    <x-ui.stat :label="__('Deductible VAT (:share%)', ['share' => (float) $purchase->deductible_share])" :value="\App\Domains\Books\Services\Purchases::money($purchase->sign() * (float) $purchase->deductible_vat_tax)" />
</div>

@if ($purchase->currency && $purchase->exchange_rate)
    <p class="mb-4 text-sm text-muted">{{ __('Billed in :currency: :gross at :rate.', ['currency' => $purchase->currency, 'gross' => $purchase->gross, 'rate' => $purchase->exchange_rate]) }}</p>
@endif
@if ($purchase->original)
    <p class="mb-4 text-sm text-muted">{{ __('Credits invoice :number.', ['number' => $purchase->original->supplier_invoice_number]) }}
        <a href="{{ route('portal.books.purchases.show', $purchase->original->id) }}" class="text-accent hover:underline">{{ __('Open it') }}</a></p>
@endif

<x-ui.card class="overflow-hidden" flush>
    <x-ui.table :primary="[0, 1, 4]">
        <x-slot:head>
            <x-ui.th>{{ __('Description') }}</x-ui.th>
            <x-ui.th>{{ __('VAT treatment') }}</x-ui.th>
            <x-ui.th align="right">{{ __('VAT rate (%)') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Net') }}</x-ui.th>
            <x-ui.th align="right">{{ __('VAT') }}</x-ui.th>
            <x-ui.th>{{ __('Capital asset') }}</x-ui.th>
        </x-slot:head>
        @foreach ($purchase->lines as $line)
            <tr>
                <td class="px-6 py-3 text-sm text-fg">{{ $line->description }}</td>
                <td class="px-6 py-3 text-sm text-muted">{{ $line->treatment->label() }}</td>
                <td class="px-6 py-3 text-end text-sm tabular-nums" dir="ltr">{{ $line->vat_rate }}</td>
                <td class="px-6 py-3 text-end text-sm"><x-ui.amount :value="$line->net_tax" /></td>
                <td class="px-6 py-3 text-end text-sm"><x-ui.amount :value="$line->vat_tax" /></td>
                <td class="px-6 py-3 text-sm text-muted">{{ $line->is_capital_asset ? __('Yes') : __('No') }}</td>
            </tr>
        @endforeach
    </x-ui.table>
</x-ui.card>

@if ($purchase->document_type === 'invoice')
    <x-ui.card :title="__('Paid to the supplier')" :description="$onPayment ? ($purchase->paid_later ? __('Marked as not paid within six months of the agreed date: its input VAT is recovered as it is paid.') : __('Its input VAT was recovered when received, on the intent to pay within six months of the agreed date.')) : null" class="mt-6 overflow-hidden" flush>
        <x-ui.table :primary="[0, 1]">
            <x-slot:head>
                <x-ui.th>{{ __('Paid on') }}</x-ui.th>
                <x-ui.th align="right">{{ __('Amount (:currency)', ['currency' => $purchase->currency]) }}</x-ui.th>
                <x-ui.th>{{ __('Reference') }}</x-ui.th>
                <x-ui.th align="right">{{ __('Actions') }}</x-ui.th>
            </x-slot:head>
            @forelse ($payments as $payment)
                <tr>
                    <td class="px-6 py-3 text-sm"><x-ui.date :value="$payment->paid_on" /></td>
                    <td class="px-6 py-3 text-end text-sm"><x-ui.amount :value="$payment->amount" /></td>
                    <td class="px-6 py-3 text-sm text-muted">{{ $payment->isReversal() ? __('Payment reversed') : ($payment->reference ?? '-') }}</td>
                    <td class="px-6 py-3 text-end text-sm">
                        @if ($records && ! $payment->isReversal() && ! in_array((string) $payment->id, $reversed, true))
                            <form method="POST" action="{{ route('portal.books.purchases.payments.reverse', $payment->id) }}"
                                  data-confirm-tone="danger" data-confirm-action="{{ __('Reverse payment') }}"
                                  data-confirm="{{ __('Reverse this payment?') }}"
                                  data-confirm-detail="{{ __('A reversal for the same amount is recorded today. The payment stays on record.') }}">
                                @csrf
                                <x-ui.button size="sm" variant="secondary">{{ __('Reverse') }}</x-ui.button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <x-ui.empty :columns="4">{{ __('No payment to the supplier is recorded yet.') }}</x-ui.empty>
            @endforelse
        </x-ui.table>
    </x-ui.card>

    @if ($records && ! $purchase->isVoid() && $toPay > 0)
        <form method="POST" action="{{ route('portal.books.purchases.pay', $purchase->id) }}" class="mt-4 grid max-w-3xl grid-cols-1 gap-4 sm:grid-cols-4">
            @csrf
            <x-ui.field :label="__('Amount (:currency)', ['currency' => $purchase->currency])" error="amount">
                <x-ui.input name="amount" inputmode="decimal" dir="ltr" required value="{{ old('amount', \App\Domains\Books\Services\Purchases::money($toPay)) }}" />
            </x-ui.field>
            <x-ui.field :label="__('Paid on')" error="paid_on">
                <x-ui.date-input name="paid_on" required value="{{ old('paid_on', now()->toDateString()) }}" />
            </x-ui.field>
            <x-ui.field :label="__('Reference')" error="reference" optional>
                <x-ui.input name="reference" maxlength="100" value="{{ old('reference') }}" />
            </x-ui.field>
            <div class="flex items-end"><x-ui.button>{{ __('Record payment') }}</x-ui.button></div>
        </form>
    @endif

    @if ($onPayment && $purchase->written_off_on)
        <p class="mt-4 text-sm text-muted">{{ __('The supplier wrote off what is unpaid, notified on :date: the input VAT recovered on it comes off in that period.', ['date' => $purchase->written_off_on->toDateString()]) }}</p>
    @elseif ($onPayment && $records && ! $purchase->isVoid() && $toPay > 0)
        <form method="POST" action="{{ route('portal.books.purchases.write-off', $purchase->id) }}" class="mt-4 flex max-w-3xl flex-wrap items-end gap-3"
              data-confirm="{{ __('Record the supplier\'s write-off?') }}"
              data-confirm-detail="{{ __('The input VAT recovered on what is unpaid comes off in the period of the notice (Federal Decree-Law No. 8 of 2017 Art 64(2)). This cannot be undone.') }}"
              data-confirm-action="{{ __('Record write-off') }}">
            @csrf
            <x-ui.field :label="__('Supplier\'s write-off notified on')" error="written_off_on">
                <x-ui.date-input name="written_off_on" required />
            </x-ui.field>
            <x-ui.button variant="secondary">{{ __('Record write-off') }}</x-ui.button>
        </form>
    @endif
@endif

@if ($records && ! $purchase->isVoid())
    <form method="POST" action="{{ route('portal.books.purchases.void', $purchase->id) }}" class="mt-6 max-w-xl space-y-3"
          data-confirm-tone="danger" data-confirm-action="{{ __('Void purchase') }}"
          data-confirm="{{ __('Void purchase :number?', ['number' => $purchase->supplier_invoice_number]) }}"
          data-confirm-detail="{{ __('It stays on record, marked void, and leaves the VAT return. It cannot be brought back; record it again if needed.') }}">
        @csrf
        <x-ui.field :label="__('Why it is void')" error="reason">
            <x-ui.input name="reason" maxlength="500" required />
        </x-ui.field>
        <x-ui.button variant="danger">{{ __('Void purchase') }}</x-ui.button>
    </form>
@endif
@endsection
