<!DOCTYPE html>
<html lang="en" class="{{ $theme->htmlClass() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in - {{ $theme->name() }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <x-theme-head />
</head>
<body class="bg-gray-100 dark:bg-gray-800 min-h-screen flex items-center justify-center">
    <div class="max-w-md w-full mx-4">
        <div class="bg-white dark:bg-gray-900 rounded-lg shadow-lg p-8">
            <div class="mb-8 flex flex-col items-center text-center">
                <x-logo :size="44" />
                <h1 class="mt-4 text-2xl font-semibold tracking-tight text-ink-900 dark:text-gray-100">{{ $theme->name() }}</h1>
                <p class="mt-1 text-gray-500 dark:text-gray-400">Sign in to continue</p>
            </div>

            @if ($errors->any())
            <div class="bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded mb-6 text-sm">
                {{ $errors->first() }}
            </div>
            @endif

            @if (session('status'))
            <div class="bg-blue-50 dark:bg-blue-950/40 border border-blue-200 dark:border-blue-800 text-blue-700 dark:text-blue-300 px-4 py-3 rounded mb-6 text-sm">
                {{ session('status') }}
            </div>
            @endif

            <form method="POST" action="{{ route('login.attempt') }}" class="space-y-5">
                @csrf

                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Email</label>
                    <x-ui.input id="email" name="email" type="email" required autofocus
                           value="{{ old('email') }}" autocomplete="username" />
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Password</label>
                    <x-ui.input id="password" name="password" type="password" required
                           autocomplete="current-password" />
                </div>

                <label class="flex items-center text-sm text-gray-600 dark:text-gray-300">
                    <input type="checkbox" name="remember" value="1" class="mr-2 text-teal-600 dark:text-teal-400">
                    Remember me
                </label>

                <x-ui.button class="w-full">Sign in</x-ui.button>
            </form>
        </div>

        <p class="text-center text-xs text-gray-400 dark:text-gray-500 mt-6">Powered by Masaar</p>
    </div>
</body>
</html>
