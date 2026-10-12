@extends('layouts.admin')

@section('title', 'Dashboard - Masaar Admin')

{{--
    Rendered server-side from App\Domains\Platform\Services\PlatformStatus, the
    same service the /api/admin/dashboard endpoints read.

    This page used to load itself from the browser with five browser-side calls
    against those endpoints. They are behind `jwt.auth`, and the console
    authenticates with a session cookie, so every call answered 401 and every
    `|| {}` fallback rendered that as a zero. Nothing here may call out: the fix
    is the data arriving with the page, and DashboardDataTest asserts that no
    browser request is left in this file.
--}}

@section('content')
@php
    $degraded = collect([
        'Database' => $health['database']['status'] ?? 'unknown',
        'Cache' => $health['cache']['status'] ?? 'unknown',
        'Queue' => $health['queue']['status'] ?? 'unknown',
    ])->reject(fn (string $status) => $status === 'healthy');

    $breaker = $health['circuit_breaker'] ?? [];
    $breakerState = $breaker['state'] ?? 'unknown';
    $breakerClosed = $breakerState === 'closed';
    $overallStatus = $health['overall_status'] ?? 'unknown';
    $queuePending = $overview['system']['queue_size'] ?? 0;
@endphp

<div>
    <!-- Issues Alert -->
    @if ($overallStatus !== 'healthy')
        @php $critical = $overallStatus === 'critical'; @endphp
        <div class="mb-6 p-4 rounded-lg {{ $critical ? 'bg-red-100 border border-red-400' : 'bg-yellow-100 border border-yellow-400' }}">
            <div class="flex items-center">
                <svg class="w-5 h-5 mr-2 {{ $critical ? 'text-red-600' : 'text-yellow-600' }}" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                </svg>
                <span class="font-medium {{ $critical ? 'text-red-800' : 'text-yellow-800' }}">
                    Platform status: {{ $overallStatus }}
                </span>
            </div>
            <ul class="mt-2 ml-7 text-sm {{ $critical ? 'text-red-700' : 'text-yellow-700' }}">
                @foreach ($degraded as $dependency => $status)
                    <li>{{ $dependency }} is {{ $status }}</li>
                @endforeach
                @unless ($breakerClosed)
                    <li>ZATCA circuit breaker is {{ $breakerState }}</li>
                @endunless
                @if (($health['queue']['stuck'] ?? 0) > 0)
                    <li>{{ $health['queue']['stuck'] }} queue item(s) stuck for over 30 minutes</li>
                @endif
            </ul>
        </div>
    @endif

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
        <!-- Organizations -->
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Organizations</p>
                    <p class="text-2xl font-bold">{{ $overview['organizations']['total'] }}</p>
                </div>
                <div class="p-3 bg-blue-100 rounded-full">
                    <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg>
                </div>
            </div>
            <p class="text-xs text-gray-400 mt-2">
                {{ $overview['organizations']['active'] }} active,
                {{ $overview['organizations']['with_certificate'] }} with certificates
            </p>
        </div>

        <!-- Invoices Today -->
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Invoices Today</p>
                    <p class="text-2xl font-bold">{{ $overview['invoices']['today'] }}</p>
                </div>
                <div class="p-3 bg-green-100 rounded-full">
                    <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                </div>
            </div>
            <p class="text-xs text-gray-400 mt-2">
                {{ $overview['invoices']['total'] }} total,
                {{ $overview['invoices']['this_hour'] }} this hour
            </p>
        </div>

        <!-- Submissions -->
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Submissions</p>
                    <p class="text-2xl font-bold">{{ $overview['submissions']['cleared'] + $overview['submissions']['reported'] }}</p>
                </div>
                <div class="p-3 bg-purple-100 rounded-full">
                    <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                </div>
            </div>
            <p class="text-xs text-gray-400 mt-2">
                {{ $overview['submissions']['cleared'] }} cleared,
                {{ $overview['submissions']['reported'] }} reported,
                <span class="text-red-500">{{ $overview['submissions']['rejected'] }}</span> rejected
            </p>
        </div>

        <!-- Offline Queue -->
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Offline Queue</p>
                    <p class="text-2xl font-bold {{ $queuePending > 0 ? 'text-orange-600' : '' }}">{{ $queuePending }}</p>
                </div>
                <div class="p-3 rounded-full {{ $queuePending > 0 ? 'bg-orange-100' : 'bg-gray-100' }}">
                    <svg class="w-6 h-6 {{ $queuePending > 0 ? 'text-orange-600' : 'text-gray-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path></svg>
                </div>
            </div>
            {{--
                The old tile showed a failed count beside the pending one. That
                came from QueueReport::summary(), not from PlatformStatus, so it
                is not on this page; `stuck` is pending items that have waited
                over 30 minutes, which is what health() actually measures.
            --}}
            <p class="text-xs text-gray-400 mt-2">
                <span class="text-red-500">{{ $health['queue']['stuck'] ?? 0 }}</span> stuck over 30 min
            </p>
        </div>
    </div>

    <!-- System Health & ZATCA Circuit Breaker -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <!-- System Health -->
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold mb-4">System Health</h3>
            <div class="space-y-3">
                @php
                    $dependencies = [
                        'Database' => $health['database']['status'] ?? 'unknown',
                        'Cache' => $health['cache']['status'] ?? 'unknown',
                        'Circuit Breaker' => $breakerState,
                        'Queue' => $health['queue']['status'] ?? 'unknown',
                    ];
                    $good = ['healthy', 'closed'];
                @endphp
                @foreach ($dependencies as $label => $status)
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600">{{ $label }}</span>
                        <span class="px-2 py-1 rounded text-xs font-medium {{ in_array($status, $good, true) ? 'bg-green-100 text-green-800' : ($status === 'warning' ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800') }}">{{ $status }}</span>
                    </div>
                @endforeach
                @if (isset($health['database']['latency_ms']))
                    <p class="text-xs text-gray-400">Database latency {{ $health['database']['latency_ms'] }}ms</p>
                @endif
            </div>
        </div>

        {{--
            This card showed live ZATCA reachability — available, latency_ms,
            reason — from the ConnectivityChecker behind
            GET /api/admin/dashboard/connectivity. That check is an outbound HTTP
            call, it is not part of PlatformStatus, and nothing on a rendered page
            can make it. What health() does carry about ZATCA is the circuit
            breaker, which is the signal that actually gates submissions, so that
            is what the card shows now. The Refresh / Reset buttons went with the
            fetch calls; the breaker closes on its own after its timeout.
        --}}
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold mb-4">ZATCA Circuit Breaker</h3>
            <div class="text-center py-4">
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-full mb-3 {{ $breakerClosed ? 'bg-green-100' : 'bg-red-100' }}">
                    <svg class="w-8 h-8 {{ $breakerClosed ? 'text-green-600' : 'text-red-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        @if ($breakerClosed)
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        @else
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        @endif
                    </svg>
                </div>
                <p class="text-lg font-semibold {{ $breakerClosed ? 'text-green-600' : 'text-red-600' }}">
                    {{ $breakerClosed ? 'Closed - submissions flowing' : 'Breaker '.$breakerState }}
                </p>
                <p class="text-sm text-gray-500">
                    {{ $breaker['failures'] ?? 0 }} failure(s) of {{ $breaker['failure_threshold'] ?? 0 }} before it opens,
                    {{ $breaker['successes'] ?? 0 }} of {{ $breaker['success_threshold'] ?? 0 }} before it closes
                </p>
                @if (! empty($breaker['last_failure']))
                    <p class="text-sm text-gray-500">
                        Last failure {{ \Illuminate\Support\Carbon::createFromTimestamp($breaker['last_failure'])->diffForHumans() }}
                    </p>
                @endif
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <h3 class="text-lg font-semibold mb-4">Quick Actions</h3>
        {{--
            Process Offline Queue now posts to the console's own route, which
            runs the same Artisan command and flashes the outcome. Run Health
            Check and Reset Circuit Breaker had no web route to post to - only
            the API endpoints this page could not authenticate against - so they
            are gone rather than pretending to work.
        --}}
        <div class="flex flex-wrap items-center gap-3">
            <form method="POST" action="{{ route('admin.queue.process') }}">
                @csrf
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 text-sm">
                    Process Offline Queue
                </button>
            </form>
            <a href="{{ route('admin.dashboard') }}" class="px-4 py-2 bg-gray-600 text-white rounded hover:bg-gray-700 text-sm">
                Refresh
            </a>
            <a href="{{ route('admin.logs') }}?state=failed" class="px-4 py-2 bg-red-100 text-red-700 rounded hover:bg-red-200 text-sm">
                View Failed Submissions
            </a>
        </div>
    </div>

    <p class="text-xs text-gray-400">
        Counted {{ $overview['generated_at'] }}, dependencies checked {{ $health['checked_at'] }}.
    </p>
</div>
@endsection
