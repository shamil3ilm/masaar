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
                <p class="font-medium text-gray-800">{{ Str::limit($activeCert->serial_number ?? $activeCert->id, 16) }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-500 mb-1">Environment</p>
                <p class="font-medium text-gray-800">
                    <span class="px-2 py-1 text-xs font-medium rounded {{ $activeCert->environment === 'production' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' }}">
                        {{ ucfirst($activeCert->environment ?? 'sandbox') }}
                    </span>
                </p>
            </div>
            <div>
                <p class="text-sm text-gray-500 mb-1">Issued At</p>
                <p class="font-medium text-gray-800">{{ \Carbon\Carbon::parse($activeCert->created_at)->format('M d, Y H:i') }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-500 mb-1">Expires At</p>
                @php
                    $expiresAt = $activeCert->expires_at ?? null;
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

<!-- Certificate History -->
<div class="bg-white rounded-lg shadow">
    <div class="p-6 border-b">
        <h3 class="text-lg font-semibold text-gray-800">Certificate History</h3>
    </div>
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Certificate</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Environment</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Issued</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Expires</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Reason</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @forelse($certHistory as $cert)
            <tr class="hover:bg-gray-50 {{ $cert->status === 'active' ? 'bg-green-50' : '' }}">
                <td class="px-6 py-4">
                    <p class="text-sm font-medium text-gray-800">{{ Str::limit($cert->serial_number ?? $cert->id, 12) }}</p>
                    <p class="text-xs text-gray-500">Gen {{ $cert->generation ?? 1 }}</p>
                </td>
                <td class="px-6 py-4">
                    <span class="px-2 py-1 text-xs font-medium rounded {{ ($cert->environment ?? 'sandbox') === 'production' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' }}">
                        {{ ucfirst($cert->environment ?? 'sandbox') }}
                    </span>
                </td>
                <td class="px-6 py-4">
                    <span class="px-2 py-1 text-xs font-medium rounded-full
                        {{ $cert->status === 'active' ? 'bg-green-100 text-green-800' : '' }}
                        {{ $cert->status === 'revoked' ? 'bg-red-100 text-red-800' : '' }}
                        {{ $cert->status === 'expired' ? 'bg-orange-100 text-orange-800' : '' }}
                        {{ $cert->status === 'renewed' ? 'bg-blue-100 text-blue-800' : '' }}
                        {{ !in_array($cert->status, ['active', 'revoked', 'expired', 'renewed']) ? 'bg-gray-100 text-gray-800' : '' }}">
                        {{ ucfirst($cert->status ?? 'unknown') }}
                    </span>
                </td>
                <td class="px-6 py-4 text-sm text-gray-500">
                    {{ \Carbon\Carbon::parse($cert->created_at)->format('M d, Y') }}
                </td>
                <td class="px-6 py-4 text-sm text-gray-500">
                    @if($cert->expires_at)
                    {{ \Carbon\Carbon::parse($cert->expires_at)->format('M d, Y') }}
                    @else
                    <span class="text-gray-400">-</span>
                    @endif
                </td>
                <td class="px-6 py-4 text-sm text-gray-500">
                    @if($cert->revocation_reason)
                    <span class="text-red-600" title="{{ $cert->revocation_reason }}">
                        {{ Str::limit($cert->revocation_reason, 20) }}
                    </span>
                    @elseif($cert->renewal_reason)
                    <span class="text-blue-600" title="{{ $cert->renewal_reason }}">
                        {{ Str::limit($cert->renewal_reason, 20) }}
                    </span>
                    @else
                    <span class="text-gray-400">-</span>
                    @endif
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="6" class="px-6 py-8 text-center text-gray-500">No certificate history</td>
            </tr>
            @endforelse
        </tbody>
    </table>
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
