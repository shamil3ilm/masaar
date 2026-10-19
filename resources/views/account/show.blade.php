@extends('layouts.guest')

@section('title', __('My account'))
@section('subtitle', __('My account'))
@section('width', 'max-w-3xl')

@section('content')
<p class="mb-6 text-sm">
    <a href="{{ $user->is_platform_admin ? route('admin.dashboard') : route('portal.dashboard') }}" class="text-accent hover:underline"><span class="inline-block rtl:rotate-180">&larr;</span> {{ __('Back') }}</a>
</p>

@foreach (['success' => 'success', 'error' => 'danger'] as $key => $tone)
    @if (session($key))
        <x-ui.alert :tone="$tone" class="mb-6">{{ session($key) }}</x-ui.alert>
    @endif
@endforeach

<div class="space-y-8">
    <form method="POST" action="{{ route('account.name') }}" class="space-y-3">
        @csrf
        <h3 class="font-semibold text-fg">{{ __('Name') }}</h3>
        <x-ui.field :label="__('Your name')">
            <x-ui.input type="text" name="name" maxlength="255" required value="{{ old('name', $user->name) }}" />
        </x-ui.field>
        <x-ui.button variant="secondary">{{ __('Save name') }}</x-ui.button>
    </form>

    <form method="POST" action="{{ route('account.email') }}" class="space-y-3 border-t pt-6">
        @csrf
        <h3 class="font-semibold text-fg">{{ __('Email address') }}</h3>
        <p class="text-sm text-muted">
            {{ __('Now :email.', ['email' => $user->email]) }}
            @if ($user->pending_email)
                {{ __('Waiting for you to open the link sent to :email.', ['email' => $user->pending_email]) }}
            @endif
        </p>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-ui.field :label="__('New email address')">
                <x-ui.input type="email" name="email" maxlength="255" required />
            </x-ui.field>
            <x-ui.field :label="__('Your password')">
                <x-ui.input type="password" name="password" required autocomplete="current-password" />
            </x-ui.field>
        </div>
        <x-ui.button variant="secondary">{{ __('Send confirmation link') }}</x-ui.button>
    </form>

    <form method="POST" action="{{ route('account.password') }}" class="space-y-3 border-t pt-6">
        @csrf
        <h3 class="font-semibold text-fg">{{ __('Password') }}</h3>
        <x-ui.field :label="__('Current password')">
            <x-ui.input type="password" name="current_password" required autocomplete="current-password" />
        </x-ui.field>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-ui.field :label="__('New password')">
                <x-ui.input type="password" name="password" required autocomplete="new-password" />
            </x-ui.field>
            <x-ui.field :label="__('Confirm new password')">
                <x-ui.input type="password" name="password_confirmation" required autocomplete="new-password" />
            </x-ui.field>
        </div>
        <x-ui.button variant="secondary">{{ __('Change password') }}</x-ui.button>
    </form>

    <section id="two-factor" class="space-y-3 border-t pt-6">
        <h3 class="font-semibold text-fg">{{ __('Two-factor authentication') }}</h3>

        @if (session('recovery_codes'))
            <x-ui.alert tone="warning" :title="__('Recovery codes - each works once, shown only now:')">
                <ul class="mt-1 grid grid-cols-2 gap-1 font-mono">
                    @foreach (session('recovery_codes') as $code)
                        <li dir="ltr">{{ $code }}</li>
                    @endforeach
                </ul>
            </x-ui.alert>
        @endif

        @if ($user->hasTwoFactor())
            <p class="text-sm text-muted">{{ __('On. Signing in asks for a code from your authenticator app.') }}</p>
            @if ($cannotTurnOff)
                <p class="text-sm text-muted">{{ $cannotTurnOff }}</p>
            @else
                <form method="POST" action="{{ route('account.two-factor.off') }}" class="space-y-3"
                      data-confirm-tone="danger" data-confirm-action="{{ __('Turn off two-factor') }}"
                      data-confirm="{{ __('Turn off two-factor sign-in?') }}"
                      data-confirm-detail="{{ __('Your password alone will open the account. You can turn it on again at any time.') }}">
                    @csrf
                    <x-ui.field :label="__('A code from your app, to turn it off')">
                        <x-ui.input type="text" name="code" inputmode="numeric" maxlength="32" autocomplete="one-time-code" required />
                    </x-ui.field>
                    <x-ui.button variant="secondary">{{ __('Turn off two-factor') }}</x-ui.button>
                </form>
            @endif
        @elseif ($setupSecret)
            <p class="text-sm text-muted">{{ __('Scan this in your authenticator app, or enter the key, then type the code it shows.') }}</p>
            <div>{!! $setupQr !!}</div>
            <p class="font-mono text-sm text-fg break-all">{{ $setupSecret }}</p>
            <form method="POST" action="{{ route('account.two-factor.confirm') }}" class="space-y-3">
                @csrf
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field :label="__('Code from the app')">
                        <x-ui.input type="text" name="code" inputmode="numeric" maxlength="32" autocomplete="one-time-code" required />
                    </x-ui.field>
                    <x-ui.field :label="__('Your password')">
                        <x-ui.input type="password" name="password" required autocomplete="current-password" />
                    </x-ui.field>
                </div>
                <x-ui.button variant="primary">{{ __('Turn on two-factor') }}</x-ui.button>
            </form>
        @else
            <p class="text-sm text-muted">{{ __('Off. With it on, signing in also asks for a code from an authenticator app on your phone.') }}</p>
            <form method="POST" action="{{ route('account.two-factor.start') }}">
                @csrf
                <x-ui.button variant="secondary">{{ __('Set up two-factor') }}</x-ui.button>
            </form>
        @endif
    </section>

    <form method="POST" action="{{ route('account.sessions') }}" class="space-y-3 border-t pt-6"
          data-confirm-action="{{ __('Sign out everywhere else') }}"
          data-confirm="{{ __('Sign out of every other browser and device?') }}"
          data-confirm-detail="{{ __('This one stays signed in. The others need your password again.') }}">
        @csrf
        <h3 class="font-semibold text-fg">{{ __('Other sessions') }}</h3>
        <p class="text-sm text-muted">{{ __('Sign out of every other browser and device - after using a shared computer, say.') }}</p>
        <x-ui.field :label="__('Your password')">
            <x-ui.input type="password" name="password" required autocomplete="current-password" />
        </x-ui.field>
        <x-ui.button variant="secondary">{{ __('Sign out everywhere else') }}</x-ui.button>
    </form>

    <form method="POST" action="{{ route('account.destroy') }}" class="space-y-3 border-t pt-6"
          data-confirm-tone="danger" data-confirm-action="{{ __('Delete my account') }}"
          data-confirm="{{ __('Delete your account?') }}"
          data-confirm-detail="{{ __('Your name, email and sign-in are erased and cannot be recovered; invoices you issued stay with the company, as the law requires.') }}">
        @csrf
        <h3 class="font-semibold text-danger">{{ __('Delete my account') }}</h3>
        <p class="text-sm text-muted">
            {{ __('Your name, email address and sign-in are erased. Invoices and records the law requires a company to keep stay with that company, no longer linked to you by name.') }}
        </p>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-ui.field :label="__('Your password')">
                <x-ui.input type="password" name="password" required autocomplete="current-password" />
            </x-ui.field>
            <x-ui.field :label="__('Type DELETE')">
                <x-ui.input type="text" name="confirm" required />
            </x-ui.field>
        </div>
        <x-ui.button variant="danger">{{ __('Delete my account') }}</x-ui.button>
    </form>
</div>
@endsection
