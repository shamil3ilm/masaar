@extends('layouts.guest')

@section('title', __('Select an organization'))
@section('heading', __('ZATCA Compliance Portal'))
@section('subtitle', __('Select an organization to continue'))
@section('width', 'max-w-lg')

@section('content')
@if (! empty($error))
    <x-ui.alert tone="danger" class="mb-6">{{ $error }}</x-ui.alert>
@endif

{{-- Only organizations the signed-in user is an active member of. --}}
@if ($organizations->count() > 0)
    <form method="GET" action="{{ route('portal.dashboard') }}">
        <fieldset class="mb-6 space-y-3">
            <legend class="sr-only">{{ __('Select an organization to continue') }}</legend>
            @foreach ($organizations as $org)
                <x-ui.radio tile name="org_id" value="{{ $org->id }}" required :description="$org->vat_number ?? __('No VAT')">
                    <span class="flex flex-wrap items-center gap-2">
                        <span class="truncate">{{ $org->name }}</span>
                        <x-ui.badge :state="$org->status" />
                    </span>
                </x-ui.radio>
            @endforeach
        </fieldset>
        <x-ui.button size="lg" class="w-full">{{ __('Open the portal') }}</x-ui.button>
    </form>
@else
    <div class="py-8 text-center">
        <svg class="mx-auto mb-4 size-16 text-subtle" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
        </svg>
        <p class="text-muted">{{ __('No organizations available') }}</p>
        <p class="mt-2 text-sm text-subtle">{{ __('Your account is not an active member of any organization. Contact your administrator.') }}</p>
    </div>
@endif

<div class="mt-6 border-t border-line pt-4 text-center">
    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <x-ui.link-button tone="muted">{{ __('Sign out') }}</x-ui.link-button>
    </form>
</div>
@endsection
