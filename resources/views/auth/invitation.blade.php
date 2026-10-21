@extends('layouts.guest')

@section('title', __('Join :organization', ['organization' => $invitation->organization->name]))

@section('content')
<p class="mb-6 text-sm text-muted">
    {{-- The role it was sent with, by name: an accountant is not told "member". --}}
    {{ __('You are invited as :role, at', ['role' => \App\Domains\Organization\Enums\MemberRole::tryFrom((string) $invitation->role)?->label() ?? __('Member')]) }} <strong>{{ $invitation->email }}</strong>.
</p>

@auth
    @if (strcasecmp((string) auth()->user()->email, $invitation->email) === 0)
        <form method="POST" action="{{ route('invitations.accept', $token) }}">
            @csrf
            <x-ui.button class="w-full">{{ __('Accept and join') }}</x-ui.button>
        </form>
    @else
        <p class="mb-4 text-sm text-muted">
            {{ __('You are signed in as :email. Sign out, then open the link again and sign in as :invited.', ['email' => auth()->user()->email, 'invited' => $invitation->email]) }}
        </p>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <x-ui.button variant="ghost" class="w-full">{{ __('Sign out') }}</x-ui.button>
        </form>
    @endif
@else
    @if ($hasAccount)
        <p class="mb-4 text-sm text-muted">{{ __('That address already has an account. Sign in, then open the link again to accept.') }}</p>
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
            <x-ui.checkbox name="terms" value="1" required>
                {{ __('I accept the') }}
                    @if (config('signup.terms_url'))
                        <a href="{{ config('signup.terms_url') }}" target="_blank" rel="noopener" class="text-accent underline underline-offset-4">{{ __('terms of service') }}</a>.
                    @else
                        {{ __('terms of service') }}.
                    @endif
            </x-ui.checkbox>
            <x-ui.button size="lg" class="w-full">{{ __('Create account and join') }}</x-ui.button>
        </form>
    @endif
@endauth
@endsection
