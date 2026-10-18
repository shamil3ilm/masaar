@extends('layouts.admin')

@section('title', 'Submission Logs')

@section('content')
<div class="mb-6">
    <h1 class="text-2xl font-semibold tracking-tight text-fg">Submission Logs</h1>
    <p class="mt-1 max-w-3xl text-sm text-muted">ZATCA submission history and status</p>
</div>

<!-- State Summary -->
<div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
    <a href="{{ route('admin.logs') }}" class="bg-surface rounded-card border border-line shadow-card p-4 text-center hover:bg-sunken {{ !$state ? 'ring-2 ring-accent' : '' }}">
        <p class="text-2xl font-semibold">{{ array_sum($stateCounts->toArray()) }}</p>
        <p class="text-sm text-muted">All</p>
    </a>
    <a href="{{ route('admin.logs', ['state' => 'cleared']) }}" class="bg-surface rounded-card border border-line shadow-card p-4 text-center hover:bg-sunken {{ $state === 'cleared' ? 'ring-2 ring-success' : '' }}">
        <p class="text-2xl font-semibold text-success">{{ $stateCounts['cleared'] ?? 0 }}</p>
        <p class="text-sm text-muted">Cleared</p>
    </a>
    <a href="{{ route('admin.logs', ['state' => 'reported']) }}" class="bg-surface rounded-card border border-line shadow-card p-4 text-center hover:bg-sunken {{ $state === 'reported' ? 'ring-2 ring-accent' : '' }}">
        <p class="text-2xl font-semibold text-info">{{ $stateCounts['reported'] ?? 0 }}</p>
        <p class="text-sm text-muted">Reported</p>
    </a>
    <a href="{{ route('admin.logs', ['state' => 'rejected']) }}" class="bg-surface rounded-card border border-line shadow-card p-4 text-center hover:bg-sunken {{ $state === 'rejected' ? 'ring-2 ring-danger' : '' }}">
        <p class="text-2xl font-semibold text-danger">{{ $stateCounts['rejected'] ?? 0 }}</p>
        <p class="text-sm text-muted">Rejected</p>
    </a>
    <a href="{{ route('admin.logs', ['state' => 'failed']) }}" class="bg-surface rounded-card border border-line shadow-card p-4 text-center hover:bg-sunken {{ $state === 'failed' ? 'ring-2 ring-warning' : '' }}">
        <p class="text-2xl font-semibold text-warning">{{ $stateCounts['failed'] ?? 0 }}</p>
        <p class="text-sm text-muted">Failed</p>
    </a>
</div>

<!-- Filters -->
<div class="bg-surface rounded-card border border-line shadow-card p-4 mb-6">
    <form method="GET" class="flex flex-wrap gap-4">
        <input type="hidden" name="state" value="{{ $state }}">
        <x-ui.field label="Organization ID">
            <x-ui.input type="text" name="org_id" value="{{ $orgId }}" />
        </x-ui.field>
        <div class="flex items-end gap-2">
            <x-ui.button variant="secondary">Filter</x-ui.button>
            <x-ui.button variant="ghost" :href="route('admin.logs', $state ? ['state' => $state] : [])">
                Clear
            </x-ui.button>
        </div>
    </form>
</div>

<!-- Logs Table -->
<div class="bg-surface rounded-card border border-line shadow-card overflow-hidden">
    <x-ui.table :primary="[0, 1, 3]">
        <x-slot:head>
            <x-ui.th>Submission</x-ui.th>
            <x-ui.th>Organization</x-ui.th>
            <x-ui.th>Type</x-ui.th>
            <x-ui.th>State</x-ui.th>
            <x-ui.th>ZATCA Status</x-ui.th>
            <x-ui.th>Error</x-ui.th>
            <x-ui.th>Created</x-ui.th>
        </x-slot:head>

        @forelse($logs as $log)
        <tr class="hover:bg-sunken">
            <td class="px-6 py-4 text-sm">
                <div class="font-medium">{{ Str::limit($log->id, 8) }}</div>
                <div class="text-xs text-muted">Inv: {{ Str::limit($log->invoice_id, 8) }}</div>
            </td>
            <td class="px-6 py-4 text-sm">{{ $log->organization_name ?? Str::limit($log->org_id, 8) }}</td>
            <td class="px-6 py-4">
                <x-ui.badge tone="neutral" :label="ucfirst($log->submission_type)" />
            </td>
            <td class="px-6 py-4">
                <x-ui.badge :state="$log->state" />
            </td>
            <td class="px-6 py-4 text-sm">
                @if($log->clearance_status)
                    <span class="text-success">{{ $log->clearance_status }}</span>
                @elseif($log->reporting_status)
                    <span class="text-info">{{ $log->reporting_status }}</span>
                @else
                    <span class="text-subtle">-</span>
                @endif
            </td>
            <td class="px-6 py-4 text-sm">
                @if($log->last_error)
                    <span class="text-danger" title="{{ $log->last_error }}">
                        {{ $log->last_error_code }}: {{ Str::limit($log->last_error, 30) }}
                    </span>
                @else
                    <span class="text-subtle">-</span>
                @endif
            </td>
            <td class="px-6 py-4 text-sm text-muted">
                {{ \Carbon\Carbon::parse($log->created_at)->diffForHumans() }}
            </td>
        </tr>
        @empty
        <x-ui.empty :columns="7">No logs found</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

<div class="mt-4">
    {{ $logs->appends(request()->query())->links() }}
</div>
@endsection
