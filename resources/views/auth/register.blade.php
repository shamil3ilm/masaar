@extends('layouts.guest')

@section('title', 'Create an account')
@section('subtitle', 'Register your business for ZATCA e-invoicing')
@section('width', 'max-w-2xl')

@section('content')
<form method="POST" action="{{ route('register.store') }}" class="space-y-6">
    @csrf

    <fieldset class="space-y-4">
        <legend class="mb-2 text-sm font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">You</legend>

        <x-ui.field label="Your name">
            <x-ui.input type="text" name="name" maxlength="255" required autocomplete="name" value="{{ old('name') }}" />
        </x-ui.field>

        <x-ui.field label="Work email">
            <x-ui.input type="email" name="email" maxlength="255" required autocomplete="email" value="{{ old('email') }}" />
        </x-ui.field>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-ui.field label="Password">
                <x-ui.input type="password" name="password" required autocomplete="new-password" />
            </x-ui.field>
            <x-ui.field label="Confirm password">
                <x-ui.input type="password" name="password_confirmation" required autocomplete="new-password" />
            </x-ui.field>
        </div>
        <p class="text-xs text-gray-500 dark:text-gray-400">At least 8 characters, with upper and lower case letters, a number and a symbol.</p>
    </fieldset>

    <fieldset class="space-y-4">
        <legend class="mb-2 text-sm font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Your company, as registered with ZATCA</legend>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-ui.field label="Legal name">
                <x-ui.input type="text" name="company_name" maxlength="255" required value="{{ old('company_name') }}" />
            </x-ui.field>
            <x-ui.field label="Legal name in Arabic">
                <x-ui.input type="text" name="company_name_ar" maxlength="255" dir="rtl" value="{{ old('company_name_ar') }}" />
            </x-ui.field>
            <x-ui.field label="VAT number">
                <x-ui.input type="text" name="vat_number" inputmode="numeric" maxlength="15" required value="{{ old('vat_number') }}" />
            </x-ui.field>
            <x-ui.field label="Commercial registration (CR) number">
                <x-ui.input type="text" name="cr_number" inputmode="numeric" maxlength="10" value="{{ old('cr_number') }}" />
            </x-ui.field>
        </div>
    </fieldset>

    <fieldset class="space-y-4">
        <legend class="mb-2 text-sm font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">National address</legend>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-ui.field label="Street">
                <x-ui.input type="text" name="street" maxlength="127" required value="{{ old('street') }}" />
            </x-ui.field>
            <x-ui.field label="Building number">
                <x-ui.input type="text" name="building_number" inputmode="numeric" maxlength="4" required value="{{ old('building_number') }}" />
            </x-ui.field>
            <x-ui.field label="Additional street">
                <x-ui.input type="text" name="additional_street" maxlength="127" value="{{ old('additional_street') }}" />
            </x-ui.field>
            <x-ui.field label="District">
                <x-ui.input type="text" name="district" maxlength="127" required value="{{ old('district') }}" />
            </x-ui.field>
            <x-ui.field label="City">
                <x-ui.input type="text" name="city" maxlength="127" required value="{{ old('city') }}" />
            </x-ui.field>
            <x-ui.field label="Postal code">
                <x-ui.input type="text" name="postal_code" inputmode="numeric" maxlength="5" required value="{{ old('postal_code') }}" />
            </x-ui.field>
        </div>
    </fieldset>

    <label class="flex items-start gap-2 text-sm text-gray-600 dark:text-gray-300">
        <input type="checkbox" name="terms" value="1" class="mt-1 text-teal-600 dark:text-teal-400" @checked(old('terms')) required>
        <span>
            I accept the
            @if ($termsUrl)
                <a href="{{ $termsUrl }}" target="_blank" rel="noopener" class="text-teal-700 dark:text-teal-300 underline">terms of service</a>.
            @else
                terms of service.
            @endif
        </span>
    </label>

    <p class="text-xs text-gray-500 dark:text-gray-400">
        We review every company before it connects to ZATCA. You can sign in and prepare invoices straight away;
        connecting to ZATCA and issuing open once your company is approved.
    </p>

    <x-ui.button class="w-full">Create account</x-ui.button>

    <p class="text-center text-sm text-gray-500 dark:text-gray-400">
        Already registered? <a href="{{ route('login') }}" class="text-teal-700 dark:text-teal-300 hover:underline">Sign in</a>
    </p>
</form>
@endsection
