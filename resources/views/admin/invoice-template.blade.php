@extends('layouts.admin')

@section('title', __('Invoice template'))

@section('content')
<x-ui.page-header :title="__('Invoice template')" :description="__('How every organization\'s invoices look on paper until it sets its own: the layout, colour, logo, text and columns. The fields the law requires are printed whatever is chosen.')">
    <x-slot:actions>
        <x-ui.button variant="secondary" :href="route('admin.branding.edit')">{{ __('Branding') }}</x-ui.button>
    </x-slot:actions>
</x-ui.page-header>

@admincan('platform.configure')
@include('invoice-template.form')
@else
<p class="max-w-2xl rounded border border-line bg-surface p-4 text-sm text-muted">
    {{ __('Your admin role can see the invoice template but not change it. An owner can.') }}
</p>
@endadmincan
@endsection
