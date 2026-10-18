@extends('layouts.portal')

@section('title', __('Company'))

@section('content')
{{-- The country's own form: its module's SellerRules::companyView(), given companyData(). --}}
@include($form, ['organization' => $organization, ...$formData])

{{-- What an invoice that does not say is priced by. A select, not a
     checkbox, as on the invoice form. --}}
<form method="POST" action="{{ route('portal.company.pricing') }}" class="mt-8 rounded-lg bg-white dark:bg-gray-900 shadow">
    @csrf
    <div class="border-b p-4">
        <h3 class="font-semibold text-gray-800 dark:text-gray-100">{{ __('Prices') }}</h3>
    </div>
    <div class="space-y-3 p-4">
        @php($inclusive = (string) old('prices_include_vat', $organization->prices_include_vat ? '1' : '0'))
        <x-ui.select name="prices_include_vat" id="prices_include_vat">
            <option value="0" @selected($inclusive === '0')>{{ __('Exclude VAT - VAT is added to them') }}</option>
            <option value="1" @selected($inclusive === '1')>{{ __('Include VAT - the customer pays exactly these') }}</option>
        </x-ui.select>
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('New invoices follow this unless they say otherwise. Issued invoices keep what they were issued with.') }}</p>
        <x-ui.button variant="outline">{{ __('Save') }}</x-ui.button>
    </div>
</form>

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
