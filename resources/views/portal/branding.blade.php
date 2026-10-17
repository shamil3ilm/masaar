@extends('layouts.portal')

@section('title', __('Branding'))

@section('content')
<div class="mb-6">
    <h1 class="text-2xl font-semibold tracking-tight text-fg">{{ __('Branding') }}</h1>
    <p class="mt-1 max-w-3xl text-sm text-muted">
        {{ __('Your organization\'s colours, name and logo, over the platform\'s. They apply to your portal, and your logo is printed on your invoices.') }}
    </p>
</div>

@include('branding.form')
@endsection
