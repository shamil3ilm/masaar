<!DOCTYPE html>
<html lang="en" class="{{ $theme->htmlClass() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- The page's own title, then the platform's name from Branding. --}}
    <title>@hasSection('title')@yield('title') - @endif{{ $theme->name() }} Admin</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <x-theme-head />
</head>
<body class="bg-gray-100 dark:bg-gray-800 min-h-screen">
    <div x-data="sidebar" class="flex">
        <!-- Sidebar -->
        {{-- Covers the page only while the drawer is out, which is only ever
             below md. Closing on a tap is the gesture people expect from a
             drawer, and it is the only way out on a screen with no room for
             the toggle beside it. --}}
        <div x-show="covering" @click="close" x-cloak
             class="fixed inset-0 z-30 bg-ink-950/50" aria-hidden="true"></div>

        <aside id="sidebar" :class="panel"
               class="fixed inset-y-0 left-0 z-40 overflow-y-auto bg-ink-900 transition-transform duration-300 md:static md:z-auto md:min-h-screen md:transition-all">
            <div class="flex items-center gap-2.5 p-4 text-white">
                {{-- No tile: the sidebar is already the navy the tile would be. --}}
                <x-logo :tile="false" :size="32" class="gap-0" />
                <span class="text-lg font-semibold tracking-tight" x-show="labelled">{{ $theme->name() }}</span>
            </div>
            <nav class="mt-4">
                <a href="{{ route('admin.dashboard') }}" class="flex items-center border-l-2 px-4 py-3 text-white/70 transition-colors hover:bg-ink-800 hover:text-white {{ request()->routeIs('admin.dashboard') ? 'border-teal-400 bg-ink-800 text-white' : 'border-transparent' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
                    <span class="ml-3" x-show="labelled">Dashboard</span>
                </a>
                <a href="{{ route('admin.organizations') }}" class="flex items-center border-l-2 px-4 py-3 text-white/70 transition-colors hover:bg-ink-800 hover:text-white {{ request()->routeIs('admin.organizations*') ? 'border-teal-400 bg-ink-800 text-white' : 'border-transparent' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg>
                    <span class="ml-3" x-show="labelled">Organizations</span>
                </a>
                <a href="{{ route('admin.users.index') }}" class="flex items-center border-l-2 px-4 py-3 text-white/70 transition-colors hover:bg-ink-800 hover:text-white {{ request()->routeIs('admin.users.*') ? 'border-teal-400 bg-ink-800 text-white' : 'border-transparent' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                    <span class="ml-3" x-show="labelled">Users</span>
                </a>
                <a href="{{ route('admin.queue') }}" class="flex items-center border-l-2 px-4 py-3 text-white/70 transition-colors hover:bg-ink-800 hover:text-white {{ request()->routeIs('admin.queue') ? 'border-teal-400 bg-ink-800 text-white' : 'border-transparent' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path></svg>
                    <span class="ml-3" x-show="labelled">Offline Queue</span>
                </a>
                <a href="{{ route('admin.logs') }}" class="flex items-center border-l-2 px-4 py-3 text-white/70 transition-colors hover:bg-ink-800 hover:text-white {{ request()->routeIs('admin.logs') ? 'border-teal-400 bg-ink-800 text-white' : 'border-transparent' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    <span class="ml-3" x-show="labelled">Logs</span>
                </a>
                <a href="{{ route('admin.audit') }}" class="flex items-center border-l-2 px-4 py-3 text-white/70 transition-colors hover:bg-ink-800 hover:text-white {{ request()->routeIs('admin.audit') ? 'border-teal-400 bg-ink-800 text-white' : 'border-transparent' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path></svg>
                    <span class="ml-3" x-show="labelled">Audit log</span>
                </a>
                <a href="{{ route('admin.licences.index') }}" class="flex items-center border-l-2 px-4 py-3 text-white/70 transition-colors hover:bg-ink-800 hover:text-white {{ request()->routeIs('admin.licences.*') ? 'border-teal-400 bg-ink-800 text-white' : 'border-transparent' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path></svg>
                    <span class="ml-3" x-show="labelled">Licences</span>
                </a>
                <a href="{{ route('admin.alerts') }}" class="flex items-center border-l-2 px-4 py-3 text-white/70 transition-colors hover:bg-ink-800 hover:text-white {{ request()->routeIs('admin.alerts') ? 'border-teal-400 bg-ink-800 text-white' : 'border-transparent' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                    <span class="ml-3" x-show="labelled">Alerts</span>
                </a>
                <a href="{{ route('admin.webhooks.index') }}" class="flex items-center border-l-2 px-4 py-3 text-white/70 transition-colors hover:bg-ink-800 hover:text-white {{ request()->routeIs('admin.webhooks.*') ? 'border-teal-400 bg-ink-800 text-white' : 'border-transparent' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                    <span class="ml-3" x-show="labelled">Webhooks</span>
                </a>
                <a href="{{ route('admin.kill-switches') }}" class="flex items-center border-l-2 px-4 py-3 text-white/70 transition-colors hover:bg-ink-800 hover:text-white {{ request()->routeIs('admin.kill-switches*') ? 'border-teal-400 bg-ink-800 text-white' : 'border-transparent' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636a9 9 0 11-12.728 0M12 3v9"></path></svg>
                    <span class="ml-3" x-show="labelled">Kill switches</span>
                </a>
                <a href="{{ route('admin.settings') }}" class="flex items-center border-l-2 px-4 py-3 text-white/70 transition-colors hover:bg-ink-800 hover:text-white {{ request()->routeIs('admin.settings*') ? 'border-teal-400 bg-ink-800 text-white' : 'border-transparent' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                    <span class="ml-3" x-show="labelled">Settings</span>
                </a>
                <a href="{{ route('admin.branding.edit') }}" class="flex items-center border-l-2 px-4 py-3 text-white/70 transition-colors hover:bg-ink-800 hover:text-white {{ request()->routeIs('admin.branding*') ? 'border-teal-400 bg-ink-800 text-white' : 'border-transparent' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"></path></svg>
                    <span class="ml-3" x-show="labelled">Branding</span>
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        {{-- min-w-0 is what makes the table's scroll area work at all. A flex
             child defaults to min-width:auto, so it refuses to shrink below
             its content: the widest table pushed main wider than the screen,
             the page itself scrolled sideways, and overflow-x-auto never had
             anything to do. --}}
        <main class="min-w-0 flex-1">
            <x-zatca-environment-banner />
            <!-- Top Bar -->
            <header class="bg-white dark:bg-gray-900 shadow-sm border-b">
                <div class="flex items-center justify-between px-4 py-4 md:px-6">
                    <button type="button" @click="toggle" :aria-expanded="expanded"
                            aria-controls="sidebar" aria-label="Navigation"
                            class="-ml-2 inline-flex min-h-11 min-w-11 items-center justify-center rounded text-gray-500 dark:text-gray-400 transition-colors hover:text-gray-700 dark:hover:text-gray-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ink-900">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                    </button>
                    <div class="flex items-center space-x-4">
                        @auth
                        <span class="text-sm text-gray-600 dark:text-gray-300 border-l pl-4">{{ auth()->user()->email }}</span>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">Sign out</button>
                        </form>
                        @endauth
                    </div>
                </div>
            </header>

            <!-- Page Content -->
            <div class="p-4 md:p-6">
                @include('admin.partials.flash')
                @yield('content')
            </div>
        </main>
    </div>
    <x-ui.confirm-dialog />

    @stack('scripts')
</body>
</html>
