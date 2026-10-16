<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ $theme->htmlClass() }}" dir="{{ \App\Http\Middleware\SetLocale::rtl() ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('Recovery codes') }} - {{ $theme->name() }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <x-theme-head />
</head>
<body class="bg-gray-100 dark:bg-gray-800 min-h-screen flex items-center justify-center">
    <div class="max-w-md w-full mx-4">
        <div class="bg-white dark:bg-gray-900 rounded-lg shadow-lg p-8">
            <h1 class="text-2xl font-semibold tracking-tight text-ink-900 dark:text-gray-100">{{ __('Two-factor is on') }}</h1>

            <p class="mt-3 text-sm text-gray-700 dark:text-gray-200">
                {{ __('Save these recovery codes somewhere safe. Each one signs you in once if you lose your phone.') }}
                <strong>{{ __('They are shown only now') }}</strong> {{ __('- they cannot be displayed again.') }}
            </p>

            <ul class="my-6 grid grid-cols-2 gap-2 rounded border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-950 p-4 font-mono text-sm">
                @foreach ($codes as $code)
                    <li>{{ $code }}</li>
                @endforeach
            </ul>

            <p class="mb-6 text-xs text-gray-500 dark:text-gray-400">
                {{ __('Lost them and your phone? Another platform admin can reset your two-factor from your page under Admin → Users.') }}
            </p>

            <x-ui.button class="w-full" :href="route('admin.dashboard')">{{ __('I have saved them - continue') }}</x-ui.button>
        </div>
    </div>
    <p class="pb-6 text-center"><x-locale-switch /></p>
</body>
</html>
