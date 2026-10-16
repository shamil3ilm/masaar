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

@admincan('platform.configure')
@include('branding.form')
@else
<p class="max-w-2xl rounded border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4 text-sm text-gray-600 dark:text-gray-300">
    Your admin role can see the platform's branding but not change it. An owner can.
</p>
@endadmincan
@endsection
