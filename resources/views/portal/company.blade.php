@extends('layouts.portal')

@section('title', __('Company'))

@section('content')
<div class="mb-6">
    <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ __('Company') }}</h2>
    <p class="text-gray-500 dark:text-gray-400">
        {{ __('The seller details on every invoice you issue. ZATCA onboarding needs all of them, in the shapes ZATCA checks.') }}
    </p>
</div>

@if ($errors->any())
    <div class="mb-6 rounded border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-950/40 px-4 py-3 text-sm text-red-700 dark:text-red-300">
        <ul class="list-disc ps-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ route('portal.company.update') }}" class="mb-6 max-w-2xl">
    @csrf

    <div class="mb-6 rounded-lg bg-white dark:bg-gray-900 shadow">
        <div class="border-b p-4">
            <h3 class="font-semibold text-gray-800 dark:text-gray-100">{{ __('Registration') }}</h3>
        </div>
        <div class="space-y-4 p-4">
            <x-ui.field :label="__('Legal name')">
                <x-ui.input type="text" name="name" maxlength="255" required
                            value="{{ old('name', $organization->name) }}" />
            </x-ui.field>

            <x-ui.field :label="__('Legal name in Arabic')">
                <x-ui.input type="text" name="name_ar" maxlength="255" dir="rtl"
                            value="{{ old('name_ar', $organization->name_ar) }}" />
                <span class="mt-1 block text-xs font-normal text-gray-500 dark:text-gray-400">{{ __('Printed on invoices and in the QR when given.') }}</span>
            </x-ui.field>

            <x-ui.field :label="__('VAT number')">
                <x-ui.input type="text" name="vat_number" inputmode="numeric" maxlength="15" required dir="ltr"
                            :readonly="$onboarded"
                            value="{{ old('vat_number', $organization->vat_number) }}" />
                <span class="mt-1 block text-xs font-normal text-gray-500 dark:text-gray-400">
                    @if ($onboarded)
                        {{ __('Fixed: your ZATCA certificate is issued for it.') }}
                    @else
                        {{ __('15 digits, starting and ending with 3.') }}
                    @endif
                </span>
            </x-ui.field>

            <x-ui.field :label="__('Commercial registration (CR) number')">
                <x-ui.input type="text" name="cr_number" inputmode="numeric" maxlength="10" dir="ltr"
                            value="{{ old('cr_number', $organization->cr_number) }}" />
            </x-ui.field>
        </div>
    </div>

    <div class="mb-6 rounded-lg bg-white dark:bg-gray-900 shadow">
        <div class="border-b p-4">
            <h3 class="font-semibold text-gray-800 dark:text-gray-100">{{ __('National address') }}</h3>
        </div>
        <div class="grid grid-cols-1 gap-4 p-4 sm:grid-cols-2">
            <x-ui.field :label="__('Street')">
                <x-ui.input type="text" name="street" maxlength="127" required
                            value="{{ old('street', $organization->street) }}" />
            </x-ui.field>

            <x-ui.field :label="__('Building number')">
                <x-ui.input type="text" name="building_number" inputmode="numeric" maxlength="4" required
                            value="{{ old('building_number', $organization->building_number) }}" />
            </x-ui.field>

            <x-ui.field :label="__('Additional street')">
                <x-ui.input type="text" name="additional_street" maxlength="127"
                            value="{{ old('additional_street', $organization->additional_street) }}" />
            </x-ui.field>

            <x-ui.field :label="__('District')">
                <x-ui.input type="text" name="district" maxlength="127" required
                            value="{{ old('district', $organization->district) }}" />
            </x-ui.field>

            <x-ui.field :label="__('City')">
                <x-ui.input type="text" name="city" maxlength="127" required
                            value="{{ old('city', $organization->city) }}" />
            </x-ui.field>

            <x-ui.field :label="__('Postal code')">
                <x-ui.input type="text" name="postal_code" inputmode="numeric" maxlength="5" required
                            value="{{ old('postal_code', $organization->postal_code) }}" />
            </x-ui.field>
        </div>
    </div>

    <x-ui.button variant="primary">{{ __('Save company details') }}</x-ui.button>
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
