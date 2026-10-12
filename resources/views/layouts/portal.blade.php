<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'ZATCA Compliance Portal')</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="flex">
        <!-- Sidebar -->
        <aside class="flex min-h-screen w-64 flex-col border-r bg-white">
            <div class="border-b p-6">
                <x-logo :size="30" name name-class="text-base font-semibold tracking-tight text-ink-900" />
                <p class="mt-2 text-xs tracking-wide text-gray-400 uppercase">ZATCA Portal</p>
                @if(isset($organization))
                <p class="mt-2 text-sm font-medium text-ink-900">{{ $organization->name }}</p>
                @endif
            </div>
            <nav class="flex-1 p-4">
                {{-- The active organization lives in the session; links carry no tenant identifier. --}}
                <a href="{{ route('portal.dashboard') }}"
                   class="flex items-center px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('portal.dashboard') ? 'bg-teal-50 font-medium text-teal-800' : 'text-gray-600 hover:bg-gray-50' }}">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
                    Dashboard
                </a>
                <a href="{{ route('portal.invoices.index') }}"
                   class="flex items-center px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('portal.invoices.*') ? 'bg-teal-50 font-medium text-teal-800' : 'text-gray-600 hover:bg-gray-50' }}">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14h6m-6-4h6m4 10H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v12a2 2 0 01-2 2z"></path></svg>
                    Invoices
                </a>
                <a href="{{ route('portal.submissions') }}"
                   class="flex items-center px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('portal.submissions') ? 'bg-teal-50 font-medium text-teal-800' : 'text-gray-600 hover:bg-gray-50' }}">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    Submissions
                </a>
                <a href="{{ route('portal.certificates') }}"
                   class="flex items-center px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('portal.certificates') ? 'bg-teal-50 font-medium text-teal-800' : 'text-gray-600 hover:bg-gray-50' }}">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
                    Certificates
                </a>
            </nav>
            <div class="border-t bg-gray-50 p-4">
                @auth
                <p class="text-xs text-gray-500 truncate mb-2">{{ auth()->user()->email }}</p>
                <div class="flex items-center gap-3 mb-2">
                    <a href="{{ route('portal.switch') }}" class="text-xs text-teal-700 hover:text-teal-900">Switch organization</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="text-xs text-gray-500 hover:text-gray-700">Sign out</button>
                    </form>
                </div>
                @endauth
                <p class="text-xs text-gray-400">Powered by Masaar</p>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 p-8">
            @yield('content')
        </main>
    </div>
</body>
</html>
