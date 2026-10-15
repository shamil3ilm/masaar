<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set up two-factor - {{ $theme->name() }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <x-theme-head />
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center">
    <div class="max-w-md w-full mx-4">
        <div class="bg-white rounded-lg shadow-lg p-8">
            <div class="mb-6 flex flex-col items-center text-center">
                <x-logo :size="44" />
                <h1 class="mt-4 text-2xl font-semibold tracking-tight text-ink-900">Set up two-factor</h1>
                <p class="mt-1 text-gray-500">Platform admins sign in with a code as well as a password.</p>
            </div>

            @if ($errors->any())
            <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded mb-6 text-sm">
                {{ $errors->first() }}
            </div>
            @endif

            <ol class="mb-6 space-y-2 text-sm text-gray-700 list-decimal list-inside">
                <li>Open an authenticator app - Google Authenticator, 1Password, Microsoft Authenticator.</li>
                <li>Scan this code, or type the key below it.</li>
                <li>Enter the six-digit code the app shows.</li>
            </ol>

            {{-- Unescaped: markup QrCodeRenderer drew from the otpauth URI. --}}
            <div class="mb-3 flex justify-center">{!! $qr !!}</div>
            <p class="mb-6 text-center font-mono text-xs break-all text-gray-600">{{ $secret }}</p>

            <form method="POST" action="{{ route('login.two-factor.confirm') }}" class="space-y-5">
                @csrf

                <div>
                    <label for="code" class="block text-sm font-medium text-gray-700 mb-1">Code from the app</label>
                    <x-ui.input id="code" name="code" type="text" required autofocus
                           inputmode="numeric" autocomplete="one-time-code" maxlength="32" />
                </div>

                <x-ui.button class="w-full">Turn on two-factor</x-ui.button>
            </form>
        </div>
    </div>
</body>
</html>
