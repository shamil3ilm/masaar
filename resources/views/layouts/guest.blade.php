<!DOCTYPE html>
<html lang="en" class="{{ $theme->htmlClass() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title') - {{ $theme->name() }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <x-theme-head />
</head>
<body class="bg-gray-100 dark:bg-gray-800 min-h-screen flex items-center justify-center py-10">
    <div class="w-full mx-4 @yield('width', 'max-w-md')">
        <div class="bg-white dark:bg-gray-900 rounded-lg shadow-lg p-8">
            <div class="mb-8 flex flex-col items-center text-center">
                <x-logo :size="44" />
                <h1 class="mt-4 text-2xl font-semibold tracking-tight text-ink-900 dark:text-gray-100">{{ $theme->name() }}</h1>
                <p class="mt-1 text-gray-500 dark:text-gray-400">@yield('subtitle')</p>
            </div>

            @if ($errors->any())
            <div class="bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded mb-6 text-sm">
                <ul class="list-disc pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
            @endif

            @if (session('status'))
            <div class="bg-blue-50 dark:bg-blue-950/40 border border-blue-200 dark:border-blue-800 text-blue-700 dark:text-blue-300 px-4 py-3 rounded mb-6 text-sm">
                {{ session('status') }}
            </div>
            @endif

            @yield('content')
        </div>
    </div>
</body>
</html>
