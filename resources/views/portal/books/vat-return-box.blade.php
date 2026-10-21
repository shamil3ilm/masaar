@extends('layouts.portal')

@section('title', __('Box :box', ['box' => $box->key]))

{{--
    The documents behind one box of the VAT return draft: each one's share,
    adding up to the box, linked to the document.

    @param \App\Domains\Compliance\Engine\DTOs\VatReturnDraft $draft
    @param \App\Domains\Compliance\Engine\DTOs\VatReturnBox $box
    @param array<string, string> $query
--}}

@section('content')
<x-ui.page-header :title="__('Box :box', ['box' => $box->key]).' - '.__($box->label)" :description="__('From :from to :to, in :currency.', ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'currency' => $draft->currency])">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('Books'), 'href' => route('portal.books.index')], ['label' => __($draft->title), 'href' => route('portal.books.vat-return', $query)], ['label' => __('Box :box', ['box' => $box->key])]]" />
    </x-slot:breadcrumb>
</x-ui.page-header>

<x-ui.card class="overflow-hidden" flush>
    <x-ui.table :primary="[1, 2, 6]">
        <x-slot:head>
            <x-ui.th>{{ __('Date') }}</x-ui.th>
            <x-ui.th>{{ __('Document') }}</x-ui.th>
            <x-ui.th>{{ __('Customer or supplier') }}</x-ui.th>
            <x-ui.th>{{ __('Kind') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Amount') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Adjustment') }}</x-ui.th>
            <x-ui.th align="right">{{ __('VAT') }}</x-ui.th>
        </x-slot:head>
        @forelse ($box->entries as $entry)
            <tr>
                <td class="px-6 py-3 text-sm"><x-ui.date :value="$entry->date" /></td>
                <td class="px-6 py-3 text-sm">
                    <a href="{{ $entry->kind === 'sale' ? route('portal.invoices.show', $entry->id) : route('portal.books.purchases.show', $entry->id) }}" class="text-accent hover:underline" dir="ltr">{{ $entry->number }}</a>
                </td>
                <td class="px-6 py-3 text-sm text-fg">{{ $entry->party }}</td>
                <td class="px-6 py-3 text-sm text-muted">{{ $entry->kind === 'sale' ? __('Sale') : __('Purchase') }}</td>
                <td class="px-6 py-3 text-end text-sm"><x-ui.amount :value="$entry->amount" /></td>
                <td class="px-6 py-3 text-end text-sm"><x-ui.amount :value="$entry->adjustment" /></td>
                <td class="px-6 py-3 text-end text-sm"><x-ui.amount :value="$entry->vat" /></td>
            </tr>
        @empty
            <x-ui.empty :columns="7">{{ __('No document is in this box for the period.') }}</x-ui.empty>
        @endforelse
        @if ($box->entries !== [])
            <tr class="font-semibold">
                <td class="px-6 py-3 text-sm" colspan="4">{{ __('Box total') }}</td>
                <td class="px-6 py-3 text-end text-sm"><x-ui.amount :value="$box->amount" /></td>
                <td class="px-6 py-3 text-end text-sm"><x-ui.amount :value="$box->adjustment" /></td>
                <td class="px-6 py-3 text-end text-sm"><x-ui.amount :value="$box->vat" /></td>
            </tr>
        @endif
    </x-ui.table>
</x-ui.card>
@endsection
