@extends('layouts.portal')

@section('title', __('Books'))

{{--
    Books' front page: where an accountant closes the month and drafts the
    VAT return.

    @param bool $records  whether the person may record here
    @param bool $opensReturn  whether they see every branch, as the VAT return needs
--}}

@section('content')
<x-ui.page-header :title="__('Books')"
                  :description="__('Purchases, what customers owe and paid, sales reports, and the VAT return drafted from them - what you need to close the month.')">
    @if ($records)
        <x-slot:actions>
            <x-ui.button :href="route('portal.books.purchases.create')">{{ __('Record a purchase') }}</x-ui.button>
        </x-slot:actions>
    @endif
</x-ui.page-header>

@unless ($records)
    <x-ui.alert tone="info" class="mb-6">{{ __('Your role reads the books. An owner, admin, member or accountant records in them.') }}</x-ui.alert>
@endunless

<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
    @foreach ([
        ...($opensReturn ? [['route' => 'portal.books.vat-return', 'title' => __('VAT return'), 'text' => __('This period\'s return, box by box, each figure with the documents behind it.')]] : []),
        ['route' => 'portal.books.purchases.index', 'title' => __('Purchases'), 'text' => __('Supplier invoices and credit notes, their VAT treatment and the input VAT you deduct.')],
        ['route' => 'portal.books.suppliers.index', 'title' => __('Suppliers'), 'text' => __('Who you buy from, and where they are.')],
        ['route' => 'portal.books.receivables', 'title' => __('Receivables'), 'text' => __('What customers owe as at any date, by age, with payments and statements.')],
        ['route' => 'portal.books.sales', 'title' => __('Sales reports'), 'text' => __('Sales by customer, item, branch, VAT category or month.')],
        ['route' => 'portal.books.vouchers', 'title' => __('Vouchers'), 'text' => __('Vouchers sold, what is left on each, and using one to pay an invoice.')],
    ] as $card)
        <a href="{{ route($card['route']) }}" class="block rounded-card border border-line bg-surface p-5 shadow-card hover:bg-sunken">
            <span class="block font-semibold text-fg">{{ $card['title'] }}</span>
            <span class="mt-1 block text-sm text-muted">{{ $card['text'] }}</span>
        </a>
    @endforeach
</div>
@endsection
