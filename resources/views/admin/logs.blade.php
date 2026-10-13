@extends('layouts.admin')

@section('title', 'Submission Logs - Masaar Admin')

@section('content')
<div class="mb-6">
    <h2 class="text-2xl font-bold text-gray-800">Submission Logs</h2>
    <p class="text-gray-500">ZATCA submission history and status</p>
</div>

<!-- State Summary -->
<div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
    <a href="{{ route('admin.logs') }}" class="bg-white rounded-lg shadow p-4 text-center hover:bg-gray-50 {{ !$state ? 'ring-2 ring-teal-500' : '' }}">
        <p class="text-2xl font-bold">{{ array_sum($stateCounts->toArray()) }}</p>
        <p class="text-sm text-gray-500">All</p>
    </a>
    <a href="{{ route('admin.logs', ['state' => 'cleared']) }}" class="bg-white rounded-lg shadow p-4 text-center hover:bg-gray-50 {{ $state === 'cleared' ? 'ring-2 ring-green-500' : '' }}">
        <p class="text-2xl font-bold text-green-600">{{ $stateCounts['cleared'] ?? 0 }}</p>
        <p class="text-sm text-gray-500">Cleared</p>
    </a>
    <a href="{{ route('admin.logs', ['state' => 'reported']) }}" class="bg-white rounded-lg shadow p-4 text-center hover:bg-gray-50 {{ $state === 'reported' ? 'ring-2 ring-teal-500' : '' }}">
        <p class="text-2xl font-bold text-blue-600">{{ $stateCounts['reported'] ?? 0 }}</p>
        <p class="text-sm text-gray-500">Reported</p>
    </a>
    <a href="{{ route('admin.logs', ['state' => 'rejected']) }}" class="bg-white rounded-lg shadow p-4 text-center hover:bg-gray-50 {{ $state === 'rejected' ? 'ring-2 ring-red-500' : '' }}">
        <p class="text-2xl font-bold text-red-600">{{ $stateCounts['rejected'] ?? 0 }}</p>
        <p class="text-sm text-gray-500">Rejected</p>
    </a>
    <a href="{{ route('admin.logs', ['state' => 'failed']) }}" class="bg-white rounded-lg shadow p-4 text-center hover:bg-gray-50 {{ $state === 'failed' ? 'ring-2 ring-orange-500' : '' }}">
        <p class="text-2xl font-bold text-orange-600">{{ $stateCounts['failed'] ?? 0 }}</p>
        <p class="text-sm text-gray-500">Failed</p>
    </a>
</div>

<!-- Filters -->
<div class="bg-white rounded-lg shadow p-4 mb-6">
    <form method="GET" class="flex flex-wrap gap-4">
        <input type="hidden" name="state" value="{{ $state }}">
        <x-ui.field label="Organization ID">
            <input type="text" name="org_id" value="{{ $orgId }}"
                   class="mt-1 w-full rounded border-gray-300 text-sm font-normal text-gray-900 shadow-sm">
        </x-ui.field>
        <div class="flex items-end gap-2">
            <x-ui.button variant="secondary">Filter</x-ui.button>
            <x-ui.button variant="quiet" :href="route('admin.logs', $state ? ['state' => $state] : [])">
                Clear
            </x-ui.button>
        </div>
    </form>
</div>

<!-- Logs Table -->
<div class="bg-white rounded-lg shadow overflow-hidden">
    <x-ui.table>
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
        <tr class="hover:bg-gray-50">
            <td class="px-6 py-4 text-sm">
                <div class="font-medium">{{ Str::limit($log->id, 8) }}</div>
                <div class="text-xs text-gray-500">Inv: {{ Str::limit($log->invoice_id, 8) }}</div>
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
                    <span class="text-green-600">{{ $log->clearance_status }}</span>
                @elseif($log->reporting_status)
                    <span class="text-blue-600">{{ $log->reporting_status }}</span>
                @else
                    <span class="text-gray-400">-</span>
                @endif
            </td>
            <td class="px-6 py-4 text-sm">
                @if($log->last_error)
                    <span class="text-red-600" title="{{ $log->last_error }}">
                        {{ $log->last_error_code }}: {{ Str::limit($log->last_error, 30) }}
                    </span>
                @else
                    <span class="text-gray-400">-</span>
                @endif
            </td>
            <td class="px-6 py-4 text-sm text-gray-500">
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
