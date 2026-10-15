@extends('layouts.admin')

@section('title', 'Branding')

@section('content')
<div class="mb-6">
    <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Branding</h2>
    <p class="text-gray-500 dark:text-gray-400">
        The platform's colours, name, logo and favicon: every page starts from these, and each
        organization can set its own over them.
    </p>
</div>

@include('branding.form')
@endsection
