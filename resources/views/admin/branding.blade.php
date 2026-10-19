@extends('layouts.admin')

@section('title', 'Branding')

@section('content')
<x-ui.page-header title="Branding" description="The platform's colours, name, logo and favicon: every page starts from these, and each organization can set its own over them.">
    <x-slot:actions>
        <x-ui.button variant="secondary" :href="route('admin.invoice-template.edit')">{{ __('Invoice template') }}</x-ui.button>
    </x-slot:actions>
</x-ui.page-header>

@admincan('platform.configure')
@include('branding.form')
@else
<p class="max-w-2xl rounded border border-line bg-surface p-4 text-sm text-muted">
    Your admin role can see the platform's branding but not change it. An owner can.
</p>
@endadmincan
@endsection
