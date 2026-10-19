@extends('layouts.portal')

@section('title', __('Invoice template'))

@section('content')
<x-ui.page-header :title="__('Invoice template')" :description="__('How your invoices look on paper, over the platform\'s: the layout, colour, logo, text and columns. A branch can have its own. The fields the law requires are printed whatever is chosen.')">
    <x-slot:actions>
        <x-ui.button variant="secondary" :href="route('portal.branding.edit')">{{ __('Branding') }}</x-ui.button>
    </x-slot:actions>
</x-ui.page-header>

@if ($branches->isNotEmpty())
    {{-- Whose template: the organization's, or one branch's over it. --}}
    <form method="GET" action="{{ route('portal.invoice-template.edit') }}" class="mb-6 flex max-w-3xl flex-wrap items-end gap-3">
        <x-ui.field :label="__('Template for')">
            <x-ui.select name="branch">
                <option value="">{{ __('The organization (every branch)') }}</option>
                @foreach ($branches as $option)
                    <option value="{{ $option->id }}" @selected($branch === (string) $option->id)>{{ $option->name }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>
        <x-ui.button variant="secondary">{{ __('Show') }}</x-ui.button>
    </form>
@endif

@include('invoice-template.form')
@endsection
