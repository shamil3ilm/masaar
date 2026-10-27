@extends('layouts.portal')

@section('title', __('Purchases'))

{{--
    Purchase invoices and supplier credit notes, as a history list, with
    recording one and importing a spreadsheet of them.

    @param \Illuminate\Pagination\LengthAwarePaginator $purchases
    @param \App\Http\Listing\ListState $list
    @param bool $records
--}}

@section('content')
<x-ui.page-header :title="__('Purchases')" :description="__('Supplier invoices and credit notes in the tax currency. Credit notes are shown below zero.')">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('Books'), 'href' => route('portal.books.index')], ['label' => __('Purchases')]]" />
    </x-slot:breadcrumb>
    @if ($records)
        <x-slot:actions>
            <x-ui.button :href="route('portal.books.purchases.create')">{{ __('Record a purchase') }}</x-ui.button>
        </x-slot:actions>
    @endif
</x-ui.page-header>

<x-ui.list-filters :list="$list" />

<x-ui.card class="overflow-hidden" flush>
    <x-ui.table :primary="[2, 0, 4]">
        <x-slot:head>
            <x-ui.th :list="$list" sort="date">{{ __('Date') }}</x-ui.th>
            <x-ui.th>{{ __('Supplier') }}</x-ui.th>
            <x-ui.th :list="$list" sort="number">{{ __('Number') }}</x-ui.th>
            <x-ui.th>{{ __('Document') }}</x-ui.th>
            <x-ui.th align="right" :list="$list" sort="total">{{ __('Total') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Deductible VAT') }}</x-ui.th>
            <x-ui.th>{{ __('Status') }}</x-ui.th>
        </x-slot:head>
        @forelse ($purchases as $purchase)
            <tr class="hover:bg-sunken">
                <td class="px-6 py-3 text-sm"><x-ui.date :value="$purchase->invoice_date" /></td>
                <td class="px-6 py-3 text-sm text-fg">{{ $purchase->supplier?->name }}</td>
                <td class="px-6 py-3 text-sm"><a href="{{ route('portal.books.purchases.show', $purchase->id) }}" class="text-accent hover:underline" dir="ltr">{{ $purchase->supplier_invoice_number }}</a></td>
                <td class="px-6 py-3 text-sm text-muted">{{ $purchase->documentLabel() }}</td>
                <td class="px-6 py-3 text-end text-sm"><x-ui.amount :value="$purchase->sign() * (float) $purchase->gross_tax" /></td>
                <td class="px-6 py-3 text-end text-sm"><x-ui.amount :value="$purchase->sign() * (float) $purchase->deductible_vat_tax" /></td>
                <td class="px-6 py-3 text-sm"><x-ui.badge :tone="$purchase->isVoid() ? 'neutral' : 'success'" :label="$purchase->isVoid() ? __('Void') : __('Recorded')" /></td>
            </tr>
        @empty
            <x-ui.empty :columns="7">{{ $list->filtered() ? __('No purchase matches that filter.') : __('No purchases yet. Record one, or import a spreadsheet of them.') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</x-ui.card>

{{ $purchases->links() }}

@if ($records)
    <x-ui.section :title="__('Import purchases')" :description="__('A CSV with one row per line; rows with the same supplier and number make one document.')" collapse class="mt-6">
        <form method="POST" action="{{ route('portal.books.purchases.import') }}" enctype="multipart/form-data" class="space-y-4"
              data-confirm="{{ __('Import these purchases?') }}"
              data-confirm-detail="{{ __('Every row is checked first. If any is wrong, nothing is recorded and each problem is listed.') }}"
              data-confirm-action="{{ __('Import') }}">
            @csrf
            <x-ui.field :label="__('CSV file')" error="file">
                {{-- Not required in the markup: an empty required file input reads
                     as invalid before anything is sent. The import asks for it. --}}
                <x-ui.file name="file" accept=".csv,text/csv" />
            </x-ui.field>
            <div class="flex flex-wrap items-center gap-3">
                <x-ui.button>{{ __('Import') }}</x-ui.button>
                <a href="{{ route('portal.books.purchases.template') }}" class="text-sm text-accent hover:underline">{{ __('Download the template') }}</a>
            </div>
        </form>
    </x-ui.section>
@endif
@endsection
