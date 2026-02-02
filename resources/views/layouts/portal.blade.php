<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'ZATCA Compliance Portal')</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="flex">
        <!-- Sidebar -->
        <aside class="w-64 bg-white border-r min-h-screen">
            <div class="p-6 border-b">
                <h1 class="text-xl font-bold text-gray-800">ZATCA Portal</h1>
                @if(isset($organization))
                <p class="text-sm text-gray-500 mt-1">{{ $organization->name }}</p>
                @endif
            </div>
            <nav class="p-4">
                <a href="{{ route('portal.dashboard', ['org_id' => request('org_id')]) }}"
                   class="flex items-center px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('portal.dashboard') ? 'bg-blue-50 text-blue-700' : 'text-gray-600 hover:bg-gray-50' }}">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
                    Dashboard
                </a>
                <a href="{{ route('portal.submissions', ['org_id' => request('org_id')]) }}"
                   class="flex items-center px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('portal.submissions') ? 'bg-blue-50 text-blue-700' : 'text-gray-600 hover:bg-gray-50' }}">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    Submissions
                </a>
                <a href="{{ route('portal.certificates', ['org_id' => request('org_id')]) }}"
                   class="flex items-center px-4 py-2 rounded-lg mb-1 {{ request()->routeIs('portal.certificates') ? 'bg-blue-50 text-blue-700' : 'text-gray-600 hover:bg-gray-50' }}">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
                    Certificates
                </a>
            </nav>
            <div class="absolute bottom-0 left-0 w-64 p-4 border-t bg-gray-50">
                <p class="text-xs text-gray-400">Powered by CompliPay</p>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 p-8">
            @yield('content')
        </main>
    </div>
</body>
</html>
