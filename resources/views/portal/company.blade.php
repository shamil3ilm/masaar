@extends('layouts.portal')

@section('title', __('Company'))

@section('content')
{{-- The country's own form: its module's SellerRules::companyView(), given companyData(). --}}
@include($form, ['organization' => $organization, ...$formData])

<form method="POST" action="{{ route('portal.company.security') }}" class="mt-8 rounded-card bg-surface border border-line shadow-card">
    @csrf
    <div class="border-b p-4">
        <h3 class="font-semibold text-fg">{{ __('Sign-in security') }}</h3>
    </div>
    <div class="space-y-3 p-4">
        <x-ui.switch name="require_two_factor" value="1" off="0" :checked="(bool) $organization->require_two_factor">
            {{ __('Require two-factor authentication for everyone in :organization. Members without it are sent to set it up before they can use the portal.', ['organization' => $organization->name]) }}
        </x-ui.switch>
        <x-ui.button variant="secondary">{{ __('Save') }}</x-ui.button>
    </div>
</form>
@endsection
