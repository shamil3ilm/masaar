@extends('layouts.guest')

@section('title', 'Choose a new password')
@section('subtitle', 'Choose a new password')

@section('content')
<form method="POST" action="{{ route('password.update') }}" class="space-y-5">
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">

    <x-ui.field label="Email">
        <x-ui.input type="email" name="email" required autocomplete="username" value="{{ old('email', $email) }}" />
    </x-ui.field>

    <x-ui.field label="New password">
        <x-ui.input type="password" name="password" required autocomplete="new-password" />
    </x-ui.field>

    <x-ui.field label="Confirm new password">
        <x-ui.input type="password" name="password_confirmation" required autocomplete="new-password" />
    </x-ui.field>

    <x-ui.button class="w-full">Change password</x-ui.button>
</form>
@endsection
