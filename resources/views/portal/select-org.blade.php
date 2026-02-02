<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Organization - ZATCA Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center">
    <div class="max-w-lg w-full mx-4">
        <div class="bg-white rounded-lg shadow-lg p-8">
            <div class="text-center mb-8">
                <h1 class="text-2xl font-bold text-gray-800">ZATCA Compliance Portal</h1>
                <p class="text-gray-500 mt-2">Select an organization to view portal</p>
            </div>

            @if(isset($error))
            <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded mb-6">
                {{ $error }}
            </div>
            @endif

            <!-- Preview Mode Info -->
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                <div class="flex items-start">
                    <svg class="w-5 h-5 text-blue-500 mt-0.5 mr-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <div>
                        <p class="text-sm text-blue-800 font-medium">Preview Mode</p>
                        <p class="text-xs text-blue-600 mt-1">This is a demo/preview mode. In production, you would be automatically logged into your organization.</p>
                    </div>
                </div>
            </div>

            <!-- Organization Selection -->
            @php
                $organizations = DB::table('organizations')->orderBy('name')->limit(20)->get();
            @endphp

            @if($organizations->count() > 0)
            <form method="GET" action="{{ route('portal.dashboard') }}">
                <div class="space-y-3 mb-6">
                    @foreach($organizations as $org)
                    <label class="flex items-center p-4 border rounded-lg cursor-pointer hover:bg-gray-50 transition-colors">
                        <input type="radio" name="org_id" value="{{ $org->id }}" class="mr-4 text-blue-600">
                        <div class="flex-1 min-w-0">
                            <p class="font-medium text-gray-800 truncate">{{ $org->name }}</p>
                            <p class="text-sm text-gray-500">{{ $org->vat_number ?? 'No VAT' }}</p>
                        </div>
                        <span class="px-2 py-1 text-xs font-medium rounded-full {{ $org->status === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600' }}">
                            {{ ucfirst($org->status ?? 'unknown') }}
                        </span>
                    </label>
                    @endforeach
                </div>
                <button type="submit" class="w-full py-3 bg-blue-600 text-white rounded-lg font-medium hover:bg-blue-700 transition-colors">
                    View Portal
                </button>
            </form>
            @else
            <div class="text-center py-8">
                <svg class="w-16 h-16 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                </svg>
                <p class="text-gray-500">No organizations found</p>
                <p class="text-sm text-gray-400 mt-2">Create an organization first to use the portal</p>
            </div>
            @endif

            <!-- Manual Entry -->
            <div class="mt-6 pt-6 border-t">
                <p class="text-sm text-gray-500 mb-3">Or enter organization ID directly:</p>
                <form method="GET" action="{{ route('portal.dashboard') }}" class="flex gap-2">
                    <input type="text" name="org_id" placeholder="Organization ID (UUID)"
                           class="flex-1 border rounded-lg px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <button type="submit" class="px-4 py-2 bg-gray-600 text-white rounded-lg text-sm hover:bg-gray-700">
                        Go
                    </button>
                </form>
            </div>
        </div>

        <p class="text-center text-xs text-gray-400 mt-6">Powered by CompliPay</p>
    </div>
</body>
</html>
