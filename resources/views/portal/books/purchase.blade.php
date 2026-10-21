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

<div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
    <x-ui.stat :label="__('Date')" :value="$purchase->invoice_date->toDateString()" />
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
