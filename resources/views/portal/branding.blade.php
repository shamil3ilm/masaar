@extends('layouts.portal')

@section('title', 'Branding')

@section('content')
<div class="mb-6">
    <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Branding</h2>
    <p class="text-gray-500 dark:text-gray-400">
        Your organization's colours, name and logo, over the platform's. They apply to your portal,
        and your logo is printed on your invoices.
    </p>
</div>

@include('branding.form')
@endsection
