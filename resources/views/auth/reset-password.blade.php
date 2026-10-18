@extends('layouts.guest')

@section('title', __('Choose a new password'))
@section('subtitle', __('At least 8 characters, with upper and lower case letters, a number and a symbol.'))

@section('content')
<form method="POST" action="{{ route('password.update') }}" class="space-y-5">
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">

    <x-ui.field :label="__('Email')">
        <x-ui.input type="email" name="email" required autocomplete="username" value="{{ old('email', $email) }}" />
    </x-ui.field>

    <x-ui.field :label="__('New password')">
        <x-ui.input type="password" name="password" required autocomplete="new-password" />
    </x-ui.field>

    <x-ui.field :label="__('Confirm new password')">
        <x-ui.input type="password" name="password_confirmation" required autocomplete="new-password" />
    </x-ui.field>

    <x-ui.button size="lg" class="w-full">{{ __('Change password') }}</x-ui.button>
</form>
@endsection
