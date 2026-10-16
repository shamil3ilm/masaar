@extends('layouts.guest')

@section('title', __('Reset your password'))
@section('subtitle', __('We will email you a link to choose a new one'))

@section('content')
<form method="POST" action="{{ route('password.email') }}" class="space-y-5">
    @csrf

    <x-ui.field :label="__('Email')">
        <x-ui.input type="email" name="email" required autofocus autocomplete="username" value="{{ old('email') }}" />
    </x-ui.field>

    <x-ui.button class="w-full">{{ __('Send the link') }}</x-ui.button>

    <p class="text-center text-sm text-gray-500 dark:text-gray-400">
        <a href="{{ route('login') }}" class="text-teal-700 dark:text-teal-300 hover:underline">{{ __('Back to sign in') }}</a>
    </p>
</form>
@endsection
