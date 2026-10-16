@extends('layouts.guest')

@section('title', 'My account')
@section('subtitle', 'My account')
@section('width', 'max-w-3xl')

@section('content')
<p class="mb-6 text-sm">
    <a href="{{ $user->is_platform_admin ? route('admin.dashboard') : route('portal.dashboard') }}" class="text-teal-700 dark:text-teal-300 hover:underline">&larr; Back</a>
</p>

@foreach (['success' => 'green', 'error' => 'red'] as $key => $tone)
    @if (session($key))
        <div class="mb-6 rounded border px-4 py-3 text-sm {{ $tone === 'green' ? 'border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-950/40 text-green-800 dark:text-green-200' : 'border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-950/40 text-red-700 dark:text-red-300' }}">
            {{ session($key) }}
        </div>
    @endif
@endforeach

<div class="space-y-8">
    <form method="POST" action="{{ route('account.name') }}" class="space-y-3">
        @csrf
        <h3 class="font-semibold text-gray-800 dark:text-gray-100">Name</h3>
        <x-ui.field label="Your name">
            <x-ui.input type="text" name="name" maxlength="255" required value="{{ old('name', $user->name) }}" />
        </x-ui.field>
        <x-ui.button variant="outline">Save name</x-ui.button>
    </form>

    <form method="POST" action="{{ route('account.email') }}" class="space-y-3 border-t pt-6">
        @csrf
        <h3 class="font-semibold text-gray-800 dark:text-gray-100">Email address</h3>
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Now {{ $user->email }}.
            @if ($user->pending_email)
                Waiting for you to open the link sent to {{ $user->pending_email }}.
            @endif
        </p>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-ui.field label="New email address">
                <x-ui.input type="email" name="email" maxlength="255" required />
            </x-ui.field>
            <x-ui.field label="Your password">
                <x-ui.input type="password" name="password" required autocomplete="current-password" />
            </x-ui.field>
        </div>
        <x-ui.button variant="outline">Send confirmation link</x-ui.button>
    </form>

    <form method="POST" action="{{ route('account.password') }}" class="space-y-3 border-t pt-6">
        @csrf
        <h3 class="font-semibold text-gray-800 dark:text-gray-100">Password</h3>
        <x-ui.field label="Current password">
            <x-ui.input type="password" name="current_password" required autocomplete="current-password" />
        </x-ui.field>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-ui.field label="New password">
                <x-ui.input type="password" name="password" required autocomplete="new-password" />
            </x-ui.field>
            <x-ui.field label="Confirm new password">
                <x-ui.input type="password" name="password_confirmation" required autocomplete="new-password" />
            </x-ui.field>
        </div>
        <x-ui.button variant="outline">Change password</x-ui.button>
    </form>

    <section id="two-factor" class="space-y-3 border-t pt-6">
        <h3 class="font-semibold text-gray-800 dark:text-gray-100">Two-factor authentication</h3>

        @if (session('recovery_codes'))
            <div class="rounded border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/40 p-4">
                <p class="mb-2 text-sm font-medium text-amber-900 dark:text-amber-100">Recovery codes - each works once, shown only now:</p>
                <ul class="grid grid-cols-2 gap-1 font-mono text-sm text-amber-900 dark:text-amber-100">
                    @foreach (session('recovery_codes') as $code)
                        <li>{{ $code }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($user->hasTwoFactor())
            <p class="text-sm text-gray-600 dark:text-gray-300">On. Signing in asks for a code from your authenticator app.</p>
            @if ($cannotTurnOff)
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $cannotTurnOff }}</p>
            @else
                <form method="POST" action="{{ route('account.two-factor.off') }}" class="space-y-3">
                    @csrf
                    <x-ui.field label="A code from your app, to turn it off">
                        <x-ui.input type="text" name="code" inputmode="numeric" maxlength="32" autocomplete="one-time-code" required />
                    </x-ui.field>
                    <x-ui.button variant="outline">Turn off two-factor</x-ui.button>
                </form>
            @endif
        @elseif ($setupSecret)
            <p class="text-sm text-gray-600 dark:text-gray-300">Scan this in your authenticator app, or enter the key, then type the code it shows.</p>
            <div>{!! $setupQr !!}</div>
            <p class="font-mono text-sm text-gray-700 dark:text-gray-200 break-all">{{ $setupSecret }}</p>
            <form method="POST" action="{{ route('account.two-factor.confirm') }}" class="space-y-3">
                @csrf
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field label="Code from the app">
                        <x-ui.input type="text" name="code" inputmode="numeric" maxlength="32" autocomplete="one-time-code" required />
                    </x-ui.field>
                    <x-ui.field label="Your password">
                        <x-ui.input type="password" name="password" required autocomplete="current-password" />
                    </x-ui.field>
                </div>
                <x-ui.button variant="primary">Turn on two-factor</x-ui.button>
            </form>
        @else
            <p class="text-sm text-gray-600 dark:text-gray-300">Off. With it on, signing in also asks for a code from an authenticator app on your phone.</p>
            <form method="POST" action="{{ route('account.two-factor.start') }}">
                @csrf
                <x-ui.button variant="outline">Set up two-factor</x-ui.button>
            </form>
        @endif
    </section>

    <form method="POST" action="{{ route('account.sessions') }}" class="space-y-3 border-t pt-6">
        @csrf
        <h3 class="font-semibold text-gray-800 dark:text-gray-100">Other sessions</h3>
        <p class="text-sm text-gray-600 dark:text-gray-300">Sign out of every other browser and device - after using a shared computer, say.</p>
        <x-ui.field label="Your password">
            <x-ui.input type="password" name="password" required autocomplete="current-password" />
        </x-ui.field>
        <x-ui.button variant="outline">Sign out everywhere else</x-ui.button>
    </form>

    <form method="POST" action="{{ route('account.destroy') }}" class="space-y-3 border-t pt-6"
          data-confirm-tone="danger" data-confirm-action="Delete my account"
          data-confirm="Delete your account? Your name, email and sign-in are erased and cannot be recovered. Invoices you issued stay with the company, as the law requires.">
        @csrf
        <h3 class="font-semibold text-red-700 dark:text-red-300">Delete my account</h3>
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Your name, email address and sign-in are erased. Invoices and records the law requires a company to keep stay
            with that company, no longer linked to you by name.
        </p>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-ui.field label="Your password">
                <x-ui.input type="password" name="password" required autocomplete="current-password" />
            </x-ui.field>
            <x-ui.field label="Type DELETE">
                <x-ui.input type="text" name="confirm" required />
            </x-ui.field>
        </div>
        <x-ui.button variant="danger">Delete my account</x-ui.button>
    </form>
</div>
@endsection
