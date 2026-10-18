@extends('layouts.guest')

@section('title', __('Sign in'))
@section('subtitle', __('Sign in to continue'))

@section('content')
<form method="POST" action="{{ route('login.attempt') }}" class="space-y-5">
    @csrf

    <x-ui.field :label="__('Email')">
        <x-ui.input name="email" type="email" required autofocus value="{{ old('email') }}" autocomplete="username" />
    </x-ui.field>

    <x-ui.field :label="__('Password')">
        <x-ui.input name="password" type="password" required autocomplete="current-password" />
    </x-ui.field>

    <div class="flex flex-wrap items-center justify-between gap-x-4">
        <x-ui.checkbox name="remember" value="1">{{ __('Remember me') }}</x-ui.checkbox>
        <a href="{{ route('password.request') }}" class="inline-flex min-h-11 items-center text-sm font-medium text-accent hover:underline underline-offset-4">{{ __('Forgot your password?') }}</a>
    </div>

    <x-ui.button size="lg" class="w-full">{{ __('Sign in') }}</x-ui.button>
</form>

@if (config('signup.enabled'))
    <p class="mt-6 border-t border-line pt-6 text-center text-sm text-muted">
        {{ __('New to e-invoicing here?') }}
        <a href="{{ route('register') }}" class="font-medium text-accent hover:underline underline-offset-4">{{ __('Create an account') }}</a>
    </p>
@endif
@endsection
