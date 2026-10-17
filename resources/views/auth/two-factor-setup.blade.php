@extends('layouts.guest')

@section('title', __('Set up two-factor'))
@section('subtitle', __('Platform admins sign in with a code as well as a password.'))

@section('content')
<ol class="mb-6 space-y-3 text-sm text-fg">
    @foreach ([
        __('Open an authenticator app - Google Authenticator, 1Password, Microsoft Authenticator.'),
        __('Scan this code, or type the key below it.'),
        __('Enter the six-digit code the app shows.'),
    ] as $instruction)
        <li class="flex gap-3">
            <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-accent-soft text-xs font-semibold text-accent" dir="ltr">{{ $loop->iteration }}</span>
            <span>{{ $instruction }}</span>
        </li>
    @endforeach
</ol>

{{-- Unescaped: markup QrCodeRenderer drew from the otpauth URI. On white
     in either mode, because a scanner reads dark modules on light. --}}
<div class="mx-auto mb-3 flex w-fit justify-center rounded-tile bg-paper p-3">{!! $qr !!}</div>
<p class="mb-6 break-all text-center font-mono text-xs text-muted" dir="ltr">{{ $secret }}</p>

<form method="POST" action="{{ route('login.two-factor.confirm') }}" class="space-y-5">
    @csrf

    <x-ui.field :label="__('Code from the app')">
        <x-ui.input name="code" type="text" required autofocus dir="ltr"
                    inputmode="numeric" autocomplete="one-time-code" maxlength="32" class="font-mono tracking-widest" />
    </x-ui.field>

    <x-ui.button size="lg" class="w-full">{{ __('Turn on two-factor') }}</x-ui.button>
</form>
@endsection
