<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Two-factor - {{ $theme->name() }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <x-theme-head />
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center">
    <div class="max-w-md w-full mx-4">
        <div class="bg-white rounded-lg shadow-lg p-8">
            <div class="mb-8 flex flex-col items-center text-center">
                <x-logo :size="44" />
                <h1 class="mt-4 text-2xl font-semibold tracking-tight text-ink-900">Two-factor</h1>
                <p class="mt-1 text-gray-500">Enter the six-digit code from your authenticator app</p>
            </div>

            @if ($errors->any())
            <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded mb-6 text-sm">
                {{ $errors->first() }}
            </div>
            @endif

            <form method="POST" action="{{ route('login.two-factor.attempt') }}" class="space-y-5">
                @csrf

                <div>
                    <label for="code" class="block text-sm font-medium text-gray-700 mb-1">Code</label>
                    <x-ui.input id="code" name="code" type="text" required autofocus
                           inputmode="numeric" autocomplete="one-time-code" maxlength="32" />
                    <p class="mt-1 text-xs text-gray-500">No phone? Enter one of your recovery codes instead.</p>
                </div>

                <x-ui.button class="w-full">Verify</x-ui.button>
            </form>
        </div>

        <p class="text-center text-xs text-gray-400 mt-6">Powered by Masaar</p>
    </div>
</body>
</html>
