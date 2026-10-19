@extends('layouts.portal')

@section('title', __('Branding'))

@section('content')
<x-ui.page-header :title="__('Branding')" :description="__('Your organization\'s colours, name and logo, over the platform\'s. They apply to your portal, and your logo is printed on your invoices.')">
    <x-slot:actions>
        <x-ui.button variant="secondary" :href="route('portal.invoice-template.edit')">{{ __('Invoice template') }}</x-ui.button>
    </x-slot:actions>
</x-ui.page-header>

@include('branding.form')
@endsection
