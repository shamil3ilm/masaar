@extends('layouts.admin')

@section('title', 'Dashboard')

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

<x-ui.page-header title="Dashboard" description="How the platform is doing now: its health, its queue and what needs an admin." />

<div>
    <!-- Issues Alert -->
    @if ($overallStatus !== 'healthy')
        @php $critical = $overallStatus === 'critical'; @endphp
        <x-ui.alert :tone="$critical ? 'danger' : 'warning'" class="mb-6" :title="'Platform status: '.$overallStatus">
            <ul class="list-disc ps-5">
                @foreach ($degraded as $dependency => $status)
                    <li>{{ $dependency }} is {{ $status }}</li>
                @endforeach
                @unless ($breakerClosed)
                    <li>Tax authority circuit breaker is {{ $breakerState }}</li>
                @endunless
                @if (($health['queue']['stuck'] ?? 0) > 0)
                    <li>{{ $health['queue']['stuck'] }} queue item(s) stuck for over 30 minutes</li>
                @endif
            </ul>
        </x-ui.alert>
    @endif

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
        <!-- Organizations -->
        <div class="bg-surface rounded-card border border-line shadow-card p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-muted">Organizations</p>
                    <p class="text-2xl font-semibold">{{ $overview['organizations']['total'] }}</p>
                </div>
                <div class="p-3 bg-accent-soft rounded-full">
                    <x-ui.icon name="building" class="size-6 text-accent" />
                </div>
            </div>
            <p class="text-xs text-subtle mt-2">
                {{ $overview['organizations']['active'] }} active,
                {{ $overview['organizations']['with_certificate'] }} with certificates
            </p>
        </div>

        <!-- Invoices Today -->
        <div class="bg-surface rounded-card border border-line shadow-card p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-muted">Invoices today</p>
                    <p class="text-2xl font-semibold">{{ $overview['invoices']['today'] }}</p>
                </div>
                <div class="p-3 bg-success-soft rounded-full">
                    <x-ui.icon name="document" class="size-6 text-success" />
                </div>
            </div>
            <p class="text-xs text-subtle mt-2">
                {{ $overview['invoices']['total'] }} total,
                {{ $overview['invoices']['this_hour'] }} this hour
            </p>
        </div>

        <!-- Submissions -->
        <div class="bg-surface rounded-card border border-line shadow-card p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-muted">Submissions</p>
                    <p class="text-2xl font-semibold">{{ $overview['submissions']['cleared'] + $overview['submissions']['reported'] }}</p>
                </div>
                <div class="p-3 bg-accent-soft rounded-full">
                    <x-ui.icon name="check-circle" class="size-6 text-accent" />
                </div>
            </div>
            <p class="text-xs text-subtle mt-2">
                {{ $overview['submissions']['cleared'] }} cleared,
                {{ $overview['submissions']['reported'] }} reported,
                <span class="text-danger">{{ $overview['submissions']['rejected'] }}</span> rejected
            </p>
        </div>

        <!-- Offline Queue -->
        <div class="bg-surface rounded-card border border-line shadow-card p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-muted">Offline queue</p>
                    <p class="text-2xl font-semibold {{ $queuePending > 0 ? 'text-warning' : '' }}">{{ $queuePending }}</p>
                </div>
                <div class="p-3 rounded-full {{ $queuePending > 0 ? 'bg-warning-soft' : 'bg-sunken' }}">
                    <x-ui.icon name="queue" class="size-6 {{ $queuePending > 0 ? 'text-warning' : 'text-muted' }}" />
                </div>
            </div>
            {{--
                The old tile showed a failed count beside the pending one. That
                came from QueueReport::summary(), not from PlatformStatus, so it
                is not on this page; `stuck` is pending items that have waited
                over 30 minutes, which is what health() actually measures.
            --}}
            <p class="text-xs text-subtle mt-2">
                <span class="text-danger">{{ $health['queue']['stuck'] ?? 0 }}</span> stuck over 30 min
            </p>
        </div>
    </div>

    <!-- System health and the tax authority circuit breaker -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <!-- System Health -->
        <div class="bg-surface rounded-card border border-line shadow-card p-6">
            <h3 class="text-lg font-semibold mb-4">System health</h3>
            <div class="space-y-3">
                @php
                    $dependencies = [
                        'Database' => $health['database']['status'] ?? 'unknown',
                        'Cache' => $health['cache']['status'] ?? 'unknown',
                        'Circuit breaker' => $breakerState,
                        'Queue' => $health['queue']['status'] ?? 'unknown',
                    ];
                    $good = ['healthy', 'closed'];
                @endphp
                @foreach ($dependencies as $label => $status)
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-muted">{{ $label }}</span>
                        @php
                            $healthTone = in_array($status, $good, true)
                                ? 'success'
                                : ($status === 'warning' ? 'warning' : 'danger');
                        @endphp
                        <x-ui.badge :tone="$healthTone" :label="$status" />
                    </div>
                @endforeach
                @if (isset($health['database']['latency_ms']))
                    <p class="text-xs text-subtle">Database latency {{ $health['database']['latency_ms'] }}ms</p>
                @endif
            </div>
        </div>

        {{--
            This card showed live tax authority reachability — available, latency_ms,
            reason — from the ConnectivityChecker behind
            GET /api/admin/dashboard/connectivity. That check is an outbound HTTP
            call, it is not part of PlatformStatus, and nothing on a rendered page
            can make it. What health() does carry about the authority is the circuit
            breaker, which is the signal that actually gates submissions, so that
            is what the card shows now. The Refresh / Reset buttons went with the
            fetch calls; the breaker closes on its own after its timeout.
        --}}
        <div class="bg-surface rounded-card border border-line shadow-card p-6">
            <h3 class="text-lg font-semibold mb-4">Tax authority circuit breaker</h3>
            <div class="text-center py-4">
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-full mb-3 {{ $breakerClosed ? 'bg-success-soft' : 'bg-danger-soft' }}">
                    <svg class="w-8 h-8 {{ $breakerClosed ? 'text-success' : 'text-danger' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        @if ($breakerClosed)
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        @else
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        @endif
                    </svg>
                </div>
                <p class="text-lg font-semibold {{ $breakerClosed ? 'text-success' : 'text-danger' }}">
                    {{ $breakerClosed ? 'Closed - submissions flowing' : 'Breaker '.$breakerState }}
                </p>
                <p class="text-sm text-muted">
                    {{ $breaker['failures'] ?? 0 }} failure(s) of {{ $breaker['failure_threshold'] ?? 0 }} before it opens,
                    {{ $breaker['successes'] ?? 0 }} of {{ $breaker['success_threshold'] ?? 0 }} before it closes
                </p>
                @if (! empty($breaker['last_failure']))
                    <p class="text-sm text-muted">
                        Last failure {{ \Illuminate\Support\Carbon::createFromTimestamp($breaker['last_failure'])->diffForHumans() }}
                    </p>
                @endif
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="bg-surface rounded-card border border-line shadow-card p-6 mb-6">
        <h3 class="text-lg font-semibold mb-4">Quick actions</h3>
        {{--
            Process Offline Queue now posts to the console's own route, which
            runs the same Artisan command and flashes the outcome. Run Health
            Check and Reset Circuit Breaker had no web route to post to - only
            the API endpoints this page could not authenticate against - so they
            are gone rather than pretending to work.
        --}}
        <div class="flex flex-wrap items-center gap-3">
            @admincan('operations.run')
            <form method="POST" action="{{ route('admin.queue.process') }}">
                @csrf
                <x-ui.button>Process the offline queue</x-ui.button>
            </form>
            @endadmincan
            <x-ui.button variant="secondary" :href="route('admin.dashboard')">Refresh</x-ui.button>
            <x-ui.button variant="secondary" :href="route('admin.logs', ['state' => 'failed'])">
                View failed submissions
            </x-ui.button>
        </div>
    </div>

    <p class="text-xs text-subtle">
        Counted <x-ui.date :value="$overview['generated_at']" time />, dependencies checked <x-ui.date :value="$health['checked_at']" time />.
    </p>
</div>
@endsection
