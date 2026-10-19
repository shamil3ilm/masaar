@extends('layouts.portal')

@section('title', __('Certificates - :organization', ['organization' => $organization->name ?? __('Portal')]))

@section('content')
<x-ui.page-header :title="__('Certificate status')" :description="__('ZATCA compliance certificate management')" />

<!-- Active Certificate -->
<div class="bg-surface rounded-card border border-line shadow-card mb-8">
    <div class="p-6 border-b">
        <h3 class="text-lg font-semibold text-fg">{{ __('Active certificate') }}</h3>
    </div>
    <div class="p-6">
        @if($activeCert)
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <div>
                <p class="text-sm text-muted mb-1">{{ __('Serial number') }}</p>
                <p class="font-medium text-fg">{{ Str::limit($activeCert->serial_number ?? '-', 16) }}</p>
            </div>
            <div>
                <p class="text-sm text-muted mb-1">{{ __('Environment') }}</p>
                <p class="font-medium text-fg">
                    @php($environment = config('fatoora.environment', 'sandbox'))
                    <x-ui.badge :tone="$environment === 'production' ? 'success' : 'warning'"
                        :label="ucfirst($environment)" />
                </p>
            </div>
            <div>
                <p class="text-sm text-muted mb-1">{{ __('Valid from') }}</p>
                <p class="font-medium text-fg"><x-ui.date :value="$activeCert->valid_from" time /></p>
            </div>
            <div>
                <p class="text-sm text-muted mb-1">{{ __('Expires') }}</p>
                @php
                    $expiresAt = $activeCert->valid_to ?? null;
                    $daysLeft = $expiresAt ? (int) now()->diffInDays($expiresAt, false) : null;
                @endphp
                @if($expiresAt)
                <p class="font-medium {{ $daysLeft <= 7 ? 'text-danger' : ($daysLeft <= 30 ? 'text-warning' : 'text-fg') }}">
                    <x-ui.date :value="$expiresAt" time />
                    @if($daysLeft !== null)
                    <span class="text-sm {{ $daysLeft <= 7 ? 'text-danger' : ($daysLeft <= 30 ? 'text-warning' : 'text-muted') }}">
                        ({{ $daysLeft > 0 ? __(':days days left', ['days' => $daysLeft]) : __('Expired') }})
                    </span>
                    @endif
                </p>
                @else
                <p class="font-medium text-subtle">{{ __('Not specified') }}</p>
                @endif
            </div>
        </div>

        <!-- Certificate Health -->
        <div class="mt-6 pt-6 border-t">
            <div class="flex items-center gap-4">
                @if($daysLeft === null || $daysLeft > 30)
                <div class="flex items-center text-success">
                    <svg class="w-6 h-6 me-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span class="font-medium">{{ __('Certificate is healthy') }}</span>
                </div>
                @elseif($daysLeft > 7)
                <div class="flex items-center text-warning">
                    <svg class="w-6 h-6 me-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                    </svg>
                    <span class="font-medium">{{ __('Certificate expiring soon - renewal recommended') }}</span>
                </div>
                @else
                <div class="flex items-center text-danger">
                    <svg class="w-6 h-6 me-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span class="font-medium">{{ $daysLeft <= 0 ? __('Certificate has expired!') : __('Certificate expires in :days days - immediate renewal required', ['days' => $daysLeft]) }}</span>
                </div>
                @endif
            </div>
        </div>
        @else
        <div class="text-center py-8">
            <svg class="w-16 h-16 mx-auto text-subtle mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
            </svg>
            <p class="text-muted">{{ __('No active certificate found') }}</p>
            <p class="text-sm text-subtle mt-2">{{ __('Contact support to set up ZATCA compliance certificate') }}</p>
        </div>
        @endif
    </div>
</div>

<!-- Help Section -->
<div class="mt-8 bg-info-soft rounded-lg p-6">
    <h4 class="font-semibold text-info mb-2">{{ __('About ZATCA Certificates') }}</h4>
    <ul class="text-sm text-info space-y-1">
        <li>{{ __('Certificates are used to digitally sign invoices for ZATCA compliance') }}</li>
        <li>{{ __('Production certificates are required for live invoicing') }}</li>
        <li>{{ __('Certificates typically expire after 1 year and must be renewed') }}</li>
        <li>{{ __('Renewal is automatic when certificate is about to expire') }}</li>
    </ul>
</div>
@endsection
