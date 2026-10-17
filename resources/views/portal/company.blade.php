@extends('layouts.portal')

@section('title', __('Company'))

@section('content')
{{-- The country's own form: its module's SellerRules::companyView(), given companyData(). --}}
@include($form, ['organization' => $organization, ...$formData])

<form method="POST" action="{{ route('portal.company.security') }}" class="mt-8 rounded-lg bg-white dark:bg-gray-900 shadow">
    @csrf
    <div class="border-b p-4">
        <h3 class="font-semibold text-gray-800 dark:text-gray-100">{{ __('Sign-in security') }}</h3>
    </div>
    <div class="space-y-3 p-4">
        <label class="flex items-start gap-3 text-sm text-gray-700 dark:text-gray-200">
            <input type="hidden" name="require_two_factor" value="0">
            <input type="checkbox" name="require_two_factor" value="1" class="mt-1" @checked($organization->require_two_factor)>
            <span>{{ __('Require two-factor authentication for everyone in :organization. Members without it are sent to set it up before they can use the portal.', ['organization' => $organization->name]) }}</span>
        </label>
        <x-ui.button variant="outline">{{ __('Save') }}</x-ui.button>
    </div>
</form>
@endsection
