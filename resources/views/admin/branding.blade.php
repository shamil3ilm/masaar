@extends('layouts.admin')

@section('title', 'Branding')

@section('content')
<div class="mb-6">
    <h1 class="text-2xl font-semibold tracking-tight text-fg">Branding</h1>
    <p class="mt-1 max-w-3xl text-sm text-muted">
        The platform's colours, name, logo and favicon: every page starts from these, and each
        organization can set its own over them.
    </p>
</div>

@admincan('platform.configure')
@include('branding.form')
@else
<p class="max-w-2xl rounded border border-line bg-surface p-4 text-sm text-muted">
    Your admin role can see the platform's branding but not change it. An owner can.
</p>
@endadmincan
@endsection
