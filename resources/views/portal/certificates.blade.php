@extends('layouts.portal')

@section('title', 'Certificates - ' . ($organization->name ?? 'Portal'))

@section('content')
<div class="mb-8">
    <h2 class="text-2xl font-bold text-gray-800">Certificate Status</h2>
    <p class="text-gray-500">ZATCA compliance certificate management</p>
</div>

<!-- Active Certificate -->
<div class="bg-white rounded-lg shadow mb-8">
    <div class="p-6 border-b">
        <h3 class="text-lg font-semibold text-gray-800">Active Certificate</h3>
    </div>
    <div class="p-6">
        @if($activeCert)
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <div>
                <p class="text-sm text-gray-500 mb-1">Serial Number</p>
                <p class="font-medium text-gray-800">{{ Str::limit($activeCert->serial_number ?? '-', 16) }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-500 mb-1">Environment</p>
                <p class="font-medium text-gray-800">
                    @php($environment = config('fatoora.environment', 'sandbox'))
                    <span class="px-2 py-1 text-xs font-medium rounded {{ $environment === 'production' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' }}">
                        {{ ucfirst($environment) }}
                    </span>
                </p>
            </div>
            <div>
                <p class="text-sm text-gray-500 mb-1">Valid From</p>
                <p class="font-medium text-gray-800">{{ \Carbon\Carbon::parse($activeCert->valid_from)->format('M d, Y H:i') }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-500 mb-1">Expires At</p>
                @php
                    $expiresAt = $activeCert->valid_to ?? null;
                    $daysLeft = $expiresAt ? now()->diffInDays($expiresAt, false) : null;
                @endphp
                @if($expiresAt)
                <p class="font-medium {{ $daysLeft <= 7 ? 'text-red-600' : ($daysLeft <= 30 ? 'text-yellow-600' : 'text-gray-800') }}">
                    {{ \Carbon\Carbon::parse($expiresAt)->format('M d, Y H:i') }}
                    @if($daysLeft !== null)
                    <span class="text-sm {{ $daysLeft <= 7 ? 'text-red-500' : ($daysLeft <= 30 ? 'text-yellow-500' : 'text-gray-500') }}">
                        ({{ $daysLeft > 0 ? $daysLeft . ' days left' : 'Expired' }})
                    </span>
                    @endif
                </p>
                @else
                <p class="font-medium text-gray-400">Not specified</p>
                @endif
            </div>
        </div>

        <!-- Certificate Health -->
        <div class="mt-6 pt-6 border-t">
            <div class="flex items-center gap-4">
                @if($daysLeft === null || $daysLeft > 30)
                <div class="flex items-center text-green-600">
                    <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span class="font-medium">Certificate is healthy</span>
                </div>
                @elseif($daysLeft > 7)
                <div class="flex items-center text-yellow-600">
                    <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                    </svg>
                    <span class="font-medium">Certificate expiring soon - renewal recommended</span>
                </div>
                @else
                <div class="flex items-center text-red-600">
                    <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span class="font-medium">{{ $daysLeft <= 0 ? 'Certificate has expired!' : 'Certificate expires in ' . $daysLeft . ' days - immediate renewal required' }}</span>
                </div>
                @endif
            </div>
        </div>
        @else
        <div class="text-center py-8">
            <svg class="w-16 h-16 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
            </svg>
            <p class="text-gray-500">No active certificate found</p>
            <p class="text-sm text-gray-400 mt-2">Contact support to set up ZATCA compliance certificate</p>
        </div>
        @endif
    </div>
</div>

<!-- Help Section -->
<div class="mt-8 bg-blue-50 rounded-lg p-6">
    <h4 class="font-semibold text-blue-800 mb-2">About ZATCA Certificates</h4>
    <ul class="text-sm text-blue-700 space-y-1">
        <li>Certificates are used to digitally sign invoices for ZATCA compliance</li>
        <li>Production certificates are required for live invoicing</li>
        <li>Certificates typically expire after 1 year and must be renewed</li>
        <li>Renewal is automatic when certificate is about to expire</li>
    </ul>
</div>
@endsection
