<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recovery codes - Masaar</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center">
    <div class="max-w-md w-full mx-4">
        <div class="bg-white rounded-lg shadow-lg p-8">
            <h1 class="text-2xl font-semibold tracking-tight text-ink-900">Two-factor is on</h1>

            <p class="mt-3 text-sm text-gray-700">
                Save these recovery codes somewhere safe. Each one signs you in once if you lose your
                phone. <strong>They are shown only now</strong> - they cannot be displayed again.
            </p>

            <ul class="my-6 grid grid-cols-2 gap-2 rounded border border-gray-200 bg-gray-50 p-4 font-mono text-sm">
                @foreach ($codes as $code)
                    <li>{{ $code }}</li>
                @endforeach
            </ul>

            <p class="mb-6 text-xs text-gray-500">
                Lost them and your phone? Another platform admin can reset your two-factor from your
                page under Admin &rarr; Users.
            </p>

            <x-ui.button class="w-full" :href="route('admin.dashboard')">I have saved them - continue</x-ui.button>
        </div>
    </div>
</body>
</html>
