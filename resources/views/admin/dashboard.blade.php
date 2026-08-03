@extends('layouts.admin')

@section('title', 'Dashboard - Masaar Admin')

@section('content')
<div x-data="dashboardData()" x-init="loadData()" @refresh-dashboard.window="loadData()">
    <!-- Issues Alert -->
    <template x-if="issues.length > 0">
        <div class="mb-6 p-4 rounded-lg" :class="hasCritical ? 'bg-red-100 border border-red-400' : 'bg-yellow-100 border border-yellow-400'">
            <div class="flex items-center">
                <svg class="w-5 h-5 mr-2" :class="hasCritical ? 'text-red-600' : 'text-yellow-600'" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                </svg>
                <span class="font-medium" :class="hasCritical ? 'text-red-800' : 'text-yellow-800'" x-text="issues.length + ' issue(s) require attention'"></span>
            </div>
            <ul class="mt-2 ml-7 text-sm" :class="hasCritical ? 'text-red-700' : 'text-yellow-700'">
                <template x-for="issue in issues" :key="issue.type">
                    <li x-text="issue.message"></li>
                </template>
            </ul>
        </div>
    </template>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
        <!-- Organizations -->
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Organizations</p>
                    <p class="text-2xl font-bold" x-text="stats.organizations?.total || 0"></p>
                </div>
                <div class="p-3 bg-blue-100 rounded-full">
                    <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg>
                </div>
            </div>
            <p class="text-xs text-gray-400 mt-2">
                <span x-text="stats.organizations?.active || 0"></span> active,
                <span x-text="stats.organizations?.with_certificate || 0"></span> with certificates
            </p>
        </div>

        <!-- Invoices Today -->
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Invoices Today</p>
                    <p class="text-2xl font-bold" x-text="stats.invoices?.today || 0"></p>
                </div>
                <div class="p-3 bg-green-100 rounded-full">
                    <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                </div>
            </div>
            <p class="text-xs text-gray-400 mt-2">
                <span x-text="stats.invoices?.total || 0"></span> total
            </p>
        </div>

        <!-- Submissions -->
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Submissions</p>
                    <p class="text-2xl font-bold" x-text="(stats.submissions?.cleared || 0) + (stats.submissions?.reported || 0)"></p>
                </div>
                <div class="p-3 bg-purple-100 rounded-full">
                    <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                </div>
            </div>
            <p class="text-xs text-gray-400 mt-2">
                <span x-text="stats.submissions?.cleared || 0"></span> cleared,
                <span x-text="stats.submissions?.reported || 0"></span> reported,
                <span class="text-red-500" x-text="stats.submissions?.rejected || 0"></span> rejected
            </p>
        </div>

        <!-- Offline Queue -->
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Offline Queue</p>
                    <p class="text-2xl font-bold" :class="queue.pending > 0 ? 'text-orange-600' : ''" x-text="queue.pending || 0"></p>
                </div>
                <div class="p-3 rounded-full" :class="queue.pending > 0 ? 'bg-orange-100' : 'bg-gray-100'">
                    <svg class="w-6 h-6" :class="queue.pending > 0 ? 'text-orange-600' : 'text-gray-600'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path></svg>
                </div>
            </div>
            <p class="text-xs text-gray-400 mt-2">
                <span class="text-red-500" x-text="queue.failed || 0"></span> failed
            </p>
        </div>
    </div>

    <!-- System Health & Connectivity -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <!-- System Health -->
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold mb-4">System Health</h3>
            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600">Database</span>
                    <span class="px-2 py-1 rounded text-xs font-medium" :class="health.database?.status === 'healthy' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'" x-text="health.database?.status || 'unknown'"></span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600">Cache</span>
                    <span class="px-2 py-1 rounded text-xs font-medium" :class="health.cache?.status === 'healthy' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'" x-text="health.cache?.status || 'unknown'"></span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600">Circuit Breaker</span>
                    <span class="px-2 py-1 rounded text-xs font-medium" :class="health.circuit_breaker?.state === 'closed' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'" x-text="health.circuit_breaker?.state || 'unknown'"></span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600">Queue</span>
                    <span class="px-2 py-1 rounded text-xs font-medium" :class="health.queue?.status === 'healthy' ? 'bg-green-100 text-green-800' : (health.queue?.status === 'warning' ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800')" x-text="health.queue?.status || 'unknown'"></span>
                </div>
            </div>
        </div>

        <!-- ZATCA Connectivity -->
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold mb-4">ZATCA Connectivity</h3>
            <div class="text-center py-4">
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-full mb-3" :class="connectivity.available ? 'bg-green-100' : 'bg-red-100'">
                    <svg class="w-8 h-8" :class="connectivity.available ? 'text-green-600' : 'text-red-600'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <template x-if="connectivity.available">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </template>
                        <template x-if="!connectivity.available">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </template>
                    </svg>
                </div>
                <p class="text-lg font-semibold" :class="connectivity.available ? 'text-green-600' : 'text-red-600'" x-text="connectivity.available ? 'Online' : 'Offline'"></p>
                <p class="text-sm text-gray-500" x-show="connectivity.latency_ms" x-text="'Latency: ' + connectivity.latency_ms + 'ms'"></p>
                <p class="text-sm text-red-500" x-show="!connectivity.available && connectivity.reason" x-text="connectivity.reason"></p>
            </div>
            <div class="mt-4 flex justify-center space-x-2">
                <button @click="refreshConnectivity()" class="px-3 py-1 bg-blue-100 text-blue-700 rounded text-sm hover:bg-blue-200">
                    Refresh
                </button>
                <button x-show="health.circuit_breaker?.state === 'open'" @click="resetCircuitBreaker()" class="px-3 py-1 bg-red-100 text-red-700 rounded text-sm hover:bg-red-200">
                    Reset Circuit Breaker
                </button>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <h3 class="text-lg font-semibold mb-4">Quick Actions</h3>
        <div class="flex flex-wrap gap-3">
            <button @click="processOfflineQueue()" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 text-sm">
                Process Offline Queue
            </button>
            <button @click="runHealthCheck()" class="px-4 py-2 bg-gray-600 text-white rounded hover:bg-gray-700 text-sm">
                Run Health Check
            </button>
            <a href="{{ route('admin.logs') }}?state=failed" class="px-4 py-2 bg-red-100 text-red-700 rounded hover:bg-red-200 text-sm">
                View Failed Submissions
            </a>
        </div>
    </div>

    <!-- Toast Notifications -->
    <div x-show="toast.show" x-transition class="fixed bottom-4 right-4 px-4 py-2 rounded shadow-lg" :class="toast.type === 'success' ? 'bg-green-500 text-white' : 'bg-red-500 text-white'">
        <span x-text="toast.message"></span>
    </div>
</div>

@push('scripts')
<script>
function dashboardData() {
    return {
        stats: {},
        health: {},
        queue: {},
        connectivity: {},
        issues: [],
        hasCritical: false,
        toast: { show: false, message: '', type: 'success' },

        async loadData() {
            try {
                const [overview, health, queueData, connectivityData, issuesData] = await Promise.all([
                    fetch('/api/admin/dashboard').then(r => r.json()),
                    fetch('/api/admin/dashboard/health').then(r => r.json()),
                    fetch('/api/admin/dashboard/offline-queue').then(r => r.json()),
                    fetch('/api/admin/dashboard/connectivity').then(r => r.json()),
                    fetch('/api/admin/dashboard/issues').then(r => r.json()),
                ]);

                this.stats = overview.data || {};
                this.health = health.data || {};
                this.queue = queueData.data?.summary || {};
                this.connectivity = connectivityData.data?.zatca_api?.connectivity || {};
                this.issues = issuesData.data?.issues || [];
                this.hasCritical = issuesData.data?.has_critical || false;

                // Update system status in header
                const statusEl = document.getElementById('system-status');
                if (statusEl) {
                    const isHealthy = this.health.overall_status === 'healthy' && this.connectivity.available;
                    statusEl.textContent = isHealthy ? 'Online' : (this.connectivity.available ? 'Degraded' : 'Offline');
                    statusEl.className = 'px-3 py-1 rounded-full text-sm font-medium ' +
                        (isHealthy ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800');
                }
            } catch (error) {
                console.error('Failed to load dashboard data:', error);
            }
        },

        async refreshConnectivity() {
            try {
                const response = await fetch('/api/admin/dashboard/connectivity/refresh', { method: 'POST' });
                const data = await response.json();
                this.connectivity = data.data?.zatca_api || {};
                this.showToast('Connectivity refreshed', 'success');
            } catch (error) {
                this.showToast('Failed to refresh connectivity', 'error');
            }
        },

        async resetCircuitBreaker() {
            try {
                await fetch('/api/admin/dashboard/circuit-breaker/reset', { method: 'POST' });
                this.showToast('Circuit breaker reset', 'success');
                this.loadData();
            } catch (error) {
                this.showToast('Failed to reset circuit breaker', 'error');
            }
        },

        async processOfflineQueue() {
            try {
                const response = await fetch('/api/admin/dashboard/offline-queue/process', { method: 'POST' });
                this.showToast('Offline queue processing started', 'success');
                setTimeout(() => this.loadData(), 2000);
            } catch (error) {
                this.showToast('Failed to process queue', 'error');
            }
        },

        async runHealthCheck() {
            try {
                await fetch('/api/admin/dashboard/run-health-check', { method: 'POST' });
                this.showToast('Health check completed', 'success');
                this.loadData();
            } catch (error) {
                this.showToast('Health check failed', 'error');
            }
        },

        showToast(message, type) {
            this.toast = { show: true, message, type };
            setTimeout(() => this.toast.show = false, 3000);
        }
    };
}
</script>
@endpush
@endsection
