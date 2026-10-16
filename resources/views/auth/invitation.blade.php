@extends('layouts.guest')

@section('title', __('Join :organization', ['organization' => $invitation->organization->name]))
@section('subtitle', __('Join :organization', ['organization' => $invitation->organization->name]))

@section('content')
<p class="mb-6 text-sm text-gray-600 dark:text-gray-300">
    {{ $invitation->role === 'admin' ? __('You are invited as an admin, at') : __('You are invited as a member, at') }} <strong>{{ $invitation->email }}</strong>.
</p>

@auth
    @if (strcasecmp((string) auth()->user()->email, $invitation->email) === 0)
        <form method="POST" action="{{ route('invitations.accept', $token) }}">
            @csrf
            <x-ui.button class="w-full">{{ __('Accept and join') }}</x-ui.button>
        </form>
    @else
        <p class="mb-4 text-sm text-gray-600 dark:text-gray-300">
            {{ __('You are signed in as :email. Sign out, then open the link again and sign in as :invited.', ['email' => auth()->user()->email, 'invited' => $invitation->email]) }}
        </p>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <x-ui.button variant="outline" class="w-full">{{ __('Sign out') }}</x-ui.button>
        </form>
    @endif
@else
    @if ($hasAccount)
        <p class="mb-4 text-sm text-gray-600 dark:text-gray-300">{{ __('That address already has an account. Sign in, then open the link again to accept.') }}</p>
        <x-ui.button :href="route('login')" class="w-full">{{ __('Sign in') }}</x-ui.button>
    @else
        <form method="POST" action="{{ route('invitations.join', $token) }}" class="space-y-5">
            @csrf
            <x-ui.field :label="__('Your name')">
                <x-ui.input type="text" name="name" maxlength="255" required autocomplete="name" value="{{ old('name') }}" />
            </x-ui.field>
            <x-ui.field :label="__('Password')">
                <x-ui.input type="password" name="password" required autocomplete="new-password" />
            </x-ui.field>
            <x-ui.field :label="__('Confirm password')">
                <x-ui.input type="password" name="password_confirmation" required autocomplete="new-password" />
            </x-ui.field>
            <label class="flex items-start gap-2 text-sm text-gray-600 dark:text-gray-300">
                <input type="checkbox" name="terms" value="1" class="mt-1 text-teal-600 dark:text-teal-400" required>
                <span>{{ __('I accept the') }}
                    @if (config('signup.terms_url'))
                        <a href="{{ config('signup.terms_url') }}" target="_blank" rel="noopener" class="text-teal-700 dark:text-teal-300 underline">{{ __('terms of service') }}</a>.
                    @else
                        {{ __('terms of service') }}.
                    @endif
                </span>
            </label>
            <x-ui.button class="w-full">{{ __('Create account and join') }}</x-ui.button>
        </form>
    @endif
@endauth
@endsection
