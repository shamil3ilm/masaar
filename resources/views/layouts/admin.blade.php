<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Masaar Admin')</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <div x-data="{ sidebarOpen: true }" class="flex">
        <!-- Sidebar -->
        <aside :class="sidebarOpen ? 'w-64' : 'w-20'" class="bg-gray-900 min-h-screen transition-all duration-300">
            <div class="p-4">
                <h1 class="text-white text-xl font-bold" x-show="sidebarOpen">Masaar</h1>
                <span class="text-white text-xl font-bold" x-show="!sidebarOpen">CP</span>
            </div>
            <nav class="mt-4">
                <a href="{{ route('admin.dashboard') }}" class="flex items-center px-4 py-3 text-gray-300 hover:bg-gray-800 hover:text-white {{ request()->routeIs('admin.dashboard') ? 'bg-gray-800 text-white' : '' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
                    <span class="ml-3" x-show="sidebarOpen">Dashboard</span>
                </a>
                <a href="{{ route('admin.organizations') }}" class="flex items-center px-4 py-3 text-gray-300 hover:bg-gray-800 hover:text-white {{ request()->routeIs('admin.organizations*') ? 'bg-gray-800 text-white' : '' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg>
                    <span class="ml-3" x-show="sidebarOpen">Organizations</span>
                </a>
                <a href="{{ route('admin.queue') }}" class="flex items-center px-4 py-3 text-gray-300 hover:bg-gray-800 hover:text-white {{ request()->routeIs('admin.queue') ? 'bg-gray-800 text-white' : '' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path></svg>
                    <span class="ml-3" x-show="sidebarOpen">Offline Queue</span>
                </a>
                <a href="{{ route('admin.logs') }}" class="flex items-center px-4 py-3 text-gray-300 hover:bg-gray-800 hover:text-white {{ request()->routeIs('admin.logs') ? 'bg-gray-800 text-white' : '' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    <span class="ml-3" x-show="sidebarOpen">Logs</span>
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="flex-1">
            <!-- Top Bar -->
            <header class="bg-white shadow-sm border-b">
                <div class="flex items-center justify-between px-6 py-4">
                    <button @click="sidebarOpen = !sidebarOpen" class="text-gray-500 hover:text-gray-700">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                    </button>
                    <div class="flex items-center space-x-4">
                        <span id="system-status" class="px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800">Online</span>
                        <span class="text-sm text-gray-500">Last updated: <span id="last-updated">-</span></span>
                        @auth
                        <span class="text-sm text-gray-600 border-l pl-4">{{ auth()->user()->email }}</span>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="text-sm text-gray-500 hover:text-gray-700">Sign out</button>
                        </form>
                        @endauth
                    </div>
                </div>
            </header>

            <!-- Page Content -->
            <div class="p-6">
                @yield('content')
            </div>
        </main>
    </div>

    <script>
        // Auto-refresh dashboard data every 30 seconds
        function refreshDashboard() {
            const event = new CustomEvent('refresh-dashboard');
            document.dispatchEvent(event);
            document.getElementById('last-updated').textContent = new Date().toLocaleTimeString();
        }

        setInterval(refreshDashboard, 30000);
        document.addEventListener('DOMContentLoaded', () => {
            document.getElementById('last-updated').textContent = new Date().toLocaleTimeString();
        });
    </script>
    @stack('scripts')
</body>
</html>
