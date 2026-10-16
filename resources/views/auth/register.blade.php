@extends('layouts.guest')

@section('title', __('Create an account'))
@section('subtitle', $choice ? __('Register your business for e-invoicing') : __('Register your business for ZATCA e-invoicing'))
@section('width', 'max-w-2xl')

@section('content')
<form method="POST" action="{{ route('register.store') }}" class="space-y-6"
      @if ($choice) x-data="signupCountry" data-country="{{ $country }}" @endif>
    @csrf

    <fieldset class="space-y-4">
        <legend class="mb-2 text-sm font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('You') }}</legend>

        <x-ui.field :label="__('Your name')">
            <x-ui.input type="text" name="name" maxlength="255" required autocomplete="name" value="{{ old('name') }}" />
        </x-ui.field>

        <x-ui.field :label="__('Work email')">
            <x-ui.input type="email" name="email" maxlength="255" required autocomplete="email" value="{{ old('email') }}" />
        </x-ui.field>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-ui.field :label="__('Password')">
                <x-ui.input type="password" name="password" required autocomplete="new-password" />
            </x-ui.field>
            <x-ui.field :label="__('Confirm password')">
                <x-ui.input type="password" name="password_confirmation" required autocomplete="new-password" />
            </x-ui.field>
        </div>
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('At least 8 characters, with upper and lower case letters, a number and a symbol.') }}</p>
    </fieldset>

    <fieldset class="space-y-4">
        <legend class="mb-2 text-sm font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ $choice ? __('Your company') : __('Your company, as registered with ZATCA') }}</legend>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-ui.field :label="__('Legal name')">
                <x-ui.input type="text" name="company_name" maxlength="255" required value="{{ old('company_name') }}" />
            </x-ui.field>
            <x-ui.field :label="__('Legal name in Arabic')">
                <x-ui.input type="text" name="company_name_ar" maxlength="255" dir="rtl" value="{{ old('company_name_ar') }}" />
            </x-ui.field>
            @unless ($choice)
                @include('auth.partials.saudi-registration')
            @endunless
        </div>
    </fieldset>

    @if ($choice)
        @include('auth.partials.country-sections')
    @else
        @include('auth.partials.saudi-address')
    @endif

    <label class="flex items-start gap-2 text-sm text-gray-600 dark:text-gray-300">
        <input type="checkbox" name="terms" value="1" class="mt-1 text-teal-600 dark:text-teal-400" @checked(old('terms')) required>
        <span>
            {{ __('I accept the') }}
            @if ($termsUrl)
                <a href="{{ $termsUrl }}" target="_blank" rel="noopener" class="text-teal-700 dark:text-teal-300 underline">{{ __('terms of service') }}</a>.
            @else
                {{ __('terms of service') }}.
            @endif
        </span>
    </label>

    <p class="text-xs text-gray-500 dark:text-gray-400" @if ($choice) :hidden="notSaudi" @if ($country !== 'SA') hidden @endif @endif>
        {{ __('We review every company before it connects to ZATCA. You can sign in and prepare invoices straight away; connecting to ZATCA and issuing open once your company is approved.') }}
    </p>
    @if ($choice)
        <p class="text-xs text-gray-500 dark:text-gray-400" :hidden="notUae" @if ($country !== 'AE') hidden @endif>
            {{ __('We review every company before it can issue. You can sign in and prepare invoices straight away; issuing opens once your company is approved and you have added the Peppol endpoint your service provider issues you on your company page.') }}
        </p>
    @endif

    <x-ui.button class="w-full">{{ __('Create account') }}</x-ui.button>

    <p class="text-center text-sm text-gray-500 dark:text-gray-400">
        {{ __('Already registered?') }} <a href="{{ route('login') }}" class="text-teal-700 dark:text-teal-300 hover:underline">{{ __('Sign in') }}</a>
    </p>
</form>
@endsection
