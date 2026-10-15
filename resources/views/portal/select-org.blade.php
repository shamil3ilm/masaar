<!DOCTYPE html>
<html lang="en" class="{{ $theme->htmlClass() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Organization - ZATCA Portal</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <x-theme-head />
</head>
<body class="bg-gray-100 dark:bg-gray-800 min-h-screen flex items-center justify-center">
    <div class="max-w-lg w-full mx-4">
        <div class="bg-white dark:bg-gray-900 rounded-lg shadow-lg p-8">
            <div class="text-center mb-8">
                <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">ZATCA Compliance Portal</h1>
                <p class="text-gray-500 dark:text-gray-400 mt-2">Select an organization to continue</p>
            </div>

            @if(!empty($error))
            <div class="bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded mb-6">
                {{ $error }}
            </div>
            @endif

            {{-- Only organizations the signed-in user is an active member of. --}}
            @if($organizations->count() > 0)
            <form method="GET" action="{{ route('portal.dashboard') }}">
                <div class="space-y-3 mb-6">
                    @foreach($organizations as $org)
                    <label class="flex items-center p-4 border rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-950 transition-colors">
                        <input type="radio" name="org_id" value="{{ $org->id }}" class="mr-4 text-teal-600 dark:text-teal-400" required>
                        <div class="flex-1 min-w-0">
                            <p class="font-medium text-gray-800 dark:text-gray-100 truncate">{{ $org->name }}</p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $org->vat_number ?? 'No VAT' }}</p>
                        </div>
                        <x-ui.badge :state="$org->status" />
                    </label>
                    @endforeach
                </div>
                <x-ui.button class="w-full">View Portal</x-ui.button>
            </form>
            @else
            <div class="text-center py-8">
                <svg class="w-16 h-16 mx-auto text-gray-300 dark:text-gray-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                </svg>
                <p class="text-gray-500 dark:text-gray-400">No organizations available</p>
                <p class="text-sm text-gray-400 dark:text-gray-500 mt-2">Your account is not an active member of any organization. Contact your administrator.</p>
            </div>
            @endif

            <div class="mt-6 pt-6 border-t text-center">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">Sign out</button>
                </form>
            </div>
        </div>

        <p class="text-center text-xs text-gray-400 dark:text-gray-500 mt-6">Powered by Masaar</p>
    </div>
</body>
</html>
