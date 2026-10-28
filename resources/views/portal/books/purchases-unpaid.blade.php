@extends('layouts.portal')

@section('title', __('Purchases unpaid over :months months', ['months' => $months]))

{{--
    Supplier invoices still not paid in full the country's number of months
    after their supply: the input VAT on what is unpaid is given back in the
    return of the period that day falls in, and reclaimed in the period of a
    later payment (PurchaseLedger::unpaidOver).

    @param int $months
    @param \Carbon\CarbonImmutable $asAt
    @param list<array{purchase: \App\Domains\Books\Models\PurchaseInvoice, due: \Carbon\CarbonImmutable, unpaid: float, vat: float}> $rows
    @param string $currency
--}}

@section('content')
<x-ui.page-header :title="__('Purchases unpaid over :months months', ['months' => $months])"
                  :description="__('The input VAT on what is still unpaid is given back in the VAT return, and reclaimed in the period you pay the supplier.')">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('Books'), 'href' => route('portal.books.index')], ['label' => __('Purchases'), 'href' => route('portal.books.purchases.index')], ['label' => __('Unpaid')]]" />
    </x-slot:breadcrumb>
</x-ui.page-header>

<form method="GET" action="{{ route('portal.books.purchases.unpaid') }}" class="mb-6 flex flex-wrap items-end gap-3">
    <x-ui.field :label="__('As at')">
        <x-ui.date-input name="as_at" value="{{ $asAt->toDateString() }}" />
    </x-ui.field>
    <x-ui.button variant="secondary">{{ __('Show') }}</x-ui.button>
</form>

<x-ui.card class="overflow-hidden" flush>
    <x-ui.table :primary="[1, 0, 4]">
        <x-slot:head>
            <x-ui.th>{{ __('Supplier') }}</x-ui.th>
            <x-ui.th>{{ __('Number') }}</x-ui.th>
            <x-ui.th>{{ __('Date') }}</x-ui.th>
            <x-ui.th>{{ __('Given back from') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Unpaid') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Input VAT given back') }} ({{ $currency }})</x-ui.th>
        </x-slot:head>
        @forelse ($rows as $row)
            <tr class="hover:bg-sunken" data-unpaid-purchase>
                <td class="px-6 py-3 text-sm text-fg">{{ $row['purchase']->supplier?->name }}</td>
                <td class="px-6 py-3 text-sm"><a href="{{ route('portal.books.purchases.show', $row['purchase']->id) }}" class="text-accent hover:underline" dir="ltr">{{ $row['purchase']->supplier_invoice_number }}</a></td>
                <td class="px-6 py-3 text-sm"><x-ui.date :value="$row['purchase']->invoice_date" /></td>
                <td class="px-6 py-3 text-sm"><x-ui.date :value="$row['due']" /></td>
                <td class="px-6 py-3 text-end text-sm"><x-ui.amount :value="$row['unpaid']" :currency="$row['purchase']->currency" /></td>
                <td class="px-6 py-3 text-end text-sm"><x-ui.amount :value="$row['vat']" /></td>
            </tr>
        @empty
            <x-ui.empty :columns="6">{{ __('No purchase is unpaid that long.') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</x-ui.card>
@endsection
