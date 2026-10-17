@extends('layouts.guest')

@section('title', __('Two-factor'))
@section('subtitle', __('Enter the six-digit code from your authenticator app'))

@section('content')
<form method="POST" action="{{ route('login.two-factor.attempt') }}" class="space-y-5">
    @csrf

    <x-ui.field :label="__('Code')" :hint="__('No phone? Enter one of your recovery codes instead.')">
        <x-ui.input name="code" type="text" required autofocus dir="ltr"
                    inputmode="numeric" autocomplete="one-time-code" maxlength="32" class="font-mono tracking-widest" />
    </x-ui.field>

    <x-ui.button size="lg" class="w-full">{{ __('Verify') }}</x-ui.button>
</form>
@endsection
