@extends('layouts.guest')

@section('title', 'Join '.$invitation->organization->name)
@section('subtitle', 'Join '.$invitation->organization->name)

@section('content')
<p class="mb-6 text-sm text-gray-600 dark:text-gray-300">
    You are invited as {{ $invitation->role === 'admin' ? 'an admin' : 'a member' }}, at <strong>{{ $invitation->email }}</strong>.
</p>

@auth
    @if (strcasecmp((string) auth()->user()->email, $invitation->email) === 0)
        <form method="POST" action="{{ route('invitations.accept', $token) }}">
            @csrf
            <x-ui.button class="w-full">Accept and join</x-ui.button>
        </form>
    @else
        <p class="mb-4 text-sm text-gray-600 dark:text-gray-300">
            You are signed in as {{ auth()->user()->email }}. Sign out, then open the link again and sign in as {{ $invitation->email }}.
        </p>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <x-ui.button variant="outline" class="w-full">Sign out</x-ui.button>
        </form>
    @endif
@else
    @if ($hasAccount)
        <p class="mb-4 text-sm text-gray-600 dark:text-gray-300">That address already has an account. Sign in, then open the link again to accept.</p>
        <x-ui.button :href="route('login')" class="w-full">Sign in</x-ui.button>
    @else
        <form method="POST" action="{{ route('invitations.join', $token) }}" class="space-y-5">
            @csrf
            <x-ui.field label="Your name">
                <x-ui.input type="text" name="name" maxlength="255" required autocomplete="name" value="{{ old('name') }}" />
            </x-ui.field>
            <x-ui.field label="Password">
                <x-ui.input type="password" name="password" required autocomplete="new-password" />
            </x-ui.field>
            <x-ui.field label="Confirm password">
                <x-ui.input type="password" name="password_confirmation" required autocomplete="new-password" />
            </x-ui.field>
            <label class="flex items-start gap-2 text-sm text-gray-600 dark:text-gray-300">
                <input type="checkbox" name="terms" value="1" class="mt-1 text-teal-600 dark:text-teal-400" required>
                <span>I accept the
                    @if (config('signup.terms_url'))
                        <a href="{{ config('signup.terms_url') }}" target="_blank" rel="noopener" class="text-teal-700 dark:text-teal-300 underline">terms of service</a>.
                    @else
                        terms of service.
                    @endif
                </span>
            </label>
            <x-ui.button class="w-full">Create account and join</x-ui.button>
        </form>
    @endif
@endauth
@endsection
