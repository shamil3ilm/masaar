@extends('layouts.guest')

@section('title', __('Create an account'))
@section('subtitle', __('Register your business for e-invoicing. It takes four short steps, and your first month is free.'))
@section('width', 'max-w-3xl')

@section('content')
{{--
    Sign-up, in four steps: who you are, your company, what the country's
    authority needs to know it, and a review before anything is sent.

    One form throughout. resources/js/ui.js shows a step at a time (data-steps
    on the form, data-step on each part), checks each step with the browser's
    own validation before moving on, and fills the review from what was
    entered; without it every step shows at once and the form sends exactly
    as it did. A form sent back with errors opens on the first step that has
    one - data-step-errors names the fields - and each field shows its own
    message.

    Every field and every rule is the country module's, as before: the
    chooser and the sections come from SignupController, the fields from
    SellerRules::signupView(). Only the layout around them changed.
--}}
@php
    $steps = [
        ['title' => __('Your account'), 'hint' => __('Who will sign in')],
        ['title' => __('Your company'), 'hint' => __('Its legal name')],
        ['title' => __('Registration'), 'hint' => __('What the authority knows')],
        ['title' => __('Review'), 'hint' => __('Check and create')],
    ];
@endphp

<form method="POST" action="{{ route('register.store') }}" data-steps
      {{-- The password is never sent back, so after any refusal its step is
           to be filled again, not shown as done. --}}
      data-step-errors="{{ json_encode($errors->any() ? array_values(array_unique([...$errors->keys(), 'password', 'password_confirmation'])) : []) }}"
      @if ($choice) x-data="signupCountry" data-country="{{ $country }}" @endif>
    @csrf

    <x-ui.stepper :steps="$steps" :current="0" :label="__('Sign-up steps')" class="mb-8" />

    {{-- 1. The person --}}
    <section data-step="0" class="space-y-5" aria-labelledby="step-account">
        <header>
            <p class="text-eyebrow font-semibold uppercase text-accent">{{ __('Step :number of :total', ['number' => 1, 'total' => 4]) }}</p>
            <h2 id="step-account" data-step-heading tabindex="-1" class="mt-1 text-xl font-semibold focus:outline-none">{{ __('Your account') }}</h2>
            <p class="mt-1 text-sm text-muted">{{ __('The person who will run this account. You can invite your colleagues once you are in.') }}</p>
        </header>

        <x-ui.field :label="__('Your name')" error="name">
            <x-ui.input type="text" name="name" maxlength="255" required autocomplete="name" value="{{ old('name') }}" />
        </x-ui.field>

        <x-ui.field :label="__('Work email')" error="email" :hint="__('You sign in with this email, and we send a link to it to confirm it is yours.')">
            <x-ui.input type="email" name="email" maxlength="255" required autocomplete="email" value="{{ old('email') }}" />
        </x-ui.field>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-ui.field :label="__('Password')" error="password">
                <x-ui.input type="password" name="password" required minlength="8" autocomplete="new-password" />
            </x-ui.field>
            <x-ui.field :label="__('Confirm password')">
                <x-ui.input type="password" name="password_confirmation" required autocomplete="new-password" />
            </x-ui.field>
        </div>
        <p class="-mt-2 text-xs text-subtle">{{ __('At least 8 characters, with upper and lower case letters, a number and a symbol.') }}</p>
        @if ($errors->any() && ! $errors->has('password'))
            <p class="-mt-2 text-xs font-medium text-danger">{{ __('For your safety the password is not kept. Enter it again.') }}</p>
        @endif

        @include('auth.partials.register-nav', ['first' => true])
    </section>

    {{-- 2. The company --}}
    <section data-step="1" class="space-y-5 border-t border-line pt-8 mt-8" aria-labelledby="step-company">
        <header>
            <p class="text-eyebrow font-semibold uppercase text-accent">{{ __('Step :number of :total', ['number' => 2, 'total' => 4]) }}</p>
            <h2 id="step-company" data-step-heading tabindex="-1" class="mt-1 text-xl font-semibold focus:outline-none">{{ __('Your company') }}</h2>
            <p class="mt-1 text-sm text-muted">{{ __('The company whose invoices you will issue.') }}</p>
        </header>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-ui.field :label="__('Legal name')" error="company_name" :hint="__('Printed on every invoice you issue, exactly as on your commercial registration.')">
                <x-ui.input type="text" name="company_name" maxlength="255" required value="{{ old('company_name') }}" />
            </x-ui.field>
            <x-ui.field :label="__('Legal name in Arabic')" error="company_name_ar" :optional="! ($arabicName ?? false)" :hint="($arabicName ?? false) ? __('Printed on every invoice and in its QR code. Required: the invoice must be in Arabic.') : __('Printed beside the English name. Arabic is the authoritative text on an invoice.')">
                <x-ui.input type="text" name="company_name_ar" maxlength="255" dir="rtl" lang="ar" :required="$arabicName ?? false" value="{{ old('company_name_ar') }}" />
            </x-ui.field>
        </div>

        @if ($choice)
            @include('auth.partials.country-choice')
        @endif

        @include('auth.partials.business-type')

        {{-- Where the referral programme runs, or a referral link opened the form. --}}
        @if ($referrals ?? false)
            <x-ui.field :label="__('Referral code')" error="referral_code" optional :hint="__('If another company recommended us, its code. You both get a reward once your company qualifies.')">
                <x-ui.input type="text" name="referral_code" maxlength="20" dir="ltr" autocomplete="off" class="font-mono uppercase" value="{{ old('referral_code', $referralCode ?? '') }}" />
            </x-ui.field>
        @endif

        @include('auth.partials.register-nav')
    </section>

    {{-- 3. What the country's authority needs --}}
    <section data-step="2" class="space-y-6 border-t border-line pt-8 mt-8" aria-labelledby="step-registration">
        <header>
            <p class="text-eyebrow font-semibold uppercase text-accent">{{ __('Step :number of :total', ['number' => 3, 'total' => 4]) }}</p>
            <h2 id="step-registration" data-step-heading tabindex="-1" class="mt-1 text-xl font-semibold focus:outline-none">{{ __('Registration') }}</h2>
            <p class="mt-1 text-sm text-muted">{{ __('What the tax authority needs to know your company, exactly as it is registered. We only ask for what its rules require.') }}</p>
        </header>

        @if ($choice)
            @include('auth.partials.country-sections')
        @else
            {{-- The one country's own section (SellerRules::signupView()). --}}
            @include($sections[0]['view'])
        @endif

        @include('auth.partials.register-nav')
    </section>

    {{-- 4. Review and create --}}
    <section data-step="3" class="space-y-5 border-t border-line pt-8 mt-8" aria-labelledby="step-review">
        <header>
            <p class="text-eyebrow font-semibold uppercase text-accent">{{ __('Step :number of :total', ['number' => 4, 'total' => 4]) }}</p>
            <h2 id="step-review" data-step-heading tabindex="-1" class="mt-1 text-xl font-semibold focus:outline-none">{{ __('Review') }}</h2>
            <p class="mt-1 text-sm text-muted">{{ __('Check what you entered. You can go back to any step before you create the account.') }}</p>
        </header>

        <div data-step-review data-step-review-class="grid grid-cols-1 gap-x-6 gap-y-1 sm:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]"
             class="rounded-tile border border-line bg-sunken p-5" aria-live="polite">
            <p class="text-sm text-subtle">{{ __('Everything you entered above is sent when you create the account.') }}</p>
        </div>

        <x-ui.alert tone="info" :title="__('What happens next')">
            {{-- Each country's own words (SellerRules::signupNext()), the chosen one shown. --}}
            @foreach ($sections as $section)
                <span @if ($choice) :hidden="country !== '{{ $section['code'] }}'" @endif @if ($country !== $section['code']) hidden @endif>
                    {{ $section['next'] }}
                </span>
            @endforeach
        </x-ui.alert>

        <x-ui.checkbox name="terms" value="1" :checked="(bool) old('terms')" required>
            {{ __('I accept the') }}
            @if ($termsUrl)
                <a href="{{ $termsUrl }}" target="_blank" rel="noopener" class="text-accent underline underline-offset-4">{{ __('terms of service') }}</a>.
            @else
                {{ __('terms of service') }}.
            @endif
        </x-ui.checkbox>
        @error('terms')
            <p class="-mt-3 text-xs font-medium text-danger" data-field-error>{{ $message }}</p>
        @enderror

        <div class="flex flex-col-reverse gap-3 pt-2 sm:flex-row sm:items-center sm:justify-between">
            <x-ui.button type="button" variant="ghost" data-step-back hidden>
                <x-ui.icon name="chevron-start" class="size-4" />
                {{ __('Back') }}
            </x-ui.button>
            <x-ui.button size="lg">{{ __('Create account') }}</x-ui.button>
        </div>
    </section>

    <p class="mt-8 text-center text-sm text-muted">
        {{ __('Already registered?') }} <a href="{{ route('login') }}" class="font-medium text-accent hover:underline underline-offset-4">{{ __('Sign in') }}</a>
    </p>
</form>
@endsection
