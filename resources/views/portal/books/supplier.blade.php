@extends('layouts.portal')

@section('title', $supplier->name)

{{--
    One supplier: its details to change, and removing it.

    @param \App\Domains\Books\Models\Supplier $supplier
    @param bool $records
--}}

@section('content')
<x-ui.page-header :title="$supplier->name" :description="__('A supplier you buy from.')">
    <x-slot:breadcrumb>
        <x-ui.breadcrumb :items="[['label' => __('Books'), 'href' => route('portal.books.index')], ['label' => __('Suppliers'), 'href' => route('portal.books.suppliers.index')], ['label' => $supplier->name]]" />
    </x-slot:breadcrumb>
</x-ui.page-header>

<x-ui.card class="max-w-3xl">
    @include('portal.books.partials.supplier-fields', ['action' => route('portal.books.suppliers.update', $supplier->id), 'readOnly' => ! $records])
</x-ui.card>

@if ($records)
    <form method="POST" action="{{ route('portal.books.suppliers.remove', $supplier->id) }}" class="mt-4 max-w-3xl"
          data-confirm-tone="danger" data-confirm-action="{{ __('Remove supplier') }}"
          data-confirm="{{ __('Remove supplier :name?', ['name' => $supplier->name]) }}"
          data-confirm-detail="{{ __('It leaves the list. Purchases already recorded keep it.') }}">
        @csrf
        <x-ui.button variant="danger">{{ __('Remove supplier') }}</x-ui.button>
    </form>
@endif
@endsection
