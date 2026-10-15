<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'ZATCA Compliance Portal')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <x-theme-head />
</head>
<body class="bg-gray-50 min-h-screen">
    <div x-data="drawer" class="flex">
        <div x-show="covering" @click="close" x-cloak
             class="fixed inset-0 z-30 bg-ink-950/50" aria-hidden="true"></div>

        <!-- Sidebar -->
        <aside id="sidebar" :class="panel"
               class="fixed inset-y-0 left-0 z-40 flex flex-col overflow-y-auto border-r bg-white transition-transform duration-300 md:static md:z-auto md:min-h-screen">
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
                {{-- Its administrators only; the page itself refuses anyone else. --}}
                @if (auth()->user()?->roleIn((string) request()->attributes->get(\App\Domains\Organization\Http\Middleware\PortalTenant::ORG_ID)) === 'admin')
                <a href="{{ route('portal.branding.edit') }}"
                   class="flex items-center px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('portal.branding.*') ? 'bg-teal-50 font-medium text-teal-800' : 'text-gray-600 hover:bg-gray-50' }}">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"></path></svg>
                    Branding
                </a>
                @endif
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
        <main class="min-w-0 flex-1">
            {{-- The portal had no way to reach its navigation on a phone: the
                 sidebar was a fixed 256px column of a 390px screen and there
                 was no toggle anywhere. This bar carries one, and disappears
                 at md where the sidebar is a column again. --}}
            <div class="flex items-center gap-3 border-b bg-white px-4 py-3 md:hidden">
                <button type="button" @click="toggle" :aria-expanded="expanded"
                        aria-controls="sidebar" aria-label="Navigation"
                        class="-ml-2 inline-flex min-h-11 min-w-11 items-center justify-center rounded text-gray-500 transition-colors hover:text-gray-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ink-900">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                </button>
                <x-logo :size="26" name name-class="text-sm font-semibold tracking-tight text-ink-900" />
            </div>

            <div class="p-4 md:p-8">
            {{-- In the layout, not in each page. Included by hand it reached
                 two of eleven portal views, and the pages that redirect with a
                 message were mostly not among them. --}}
                @include('portal.partials.flash')
                @yield('content')
            </div>
        </main>
    </div>

    <x-ui.confirm-dialog />
</body>
</html>
