@extends('layouts.admin')

@section('title', 'Submission Logs - Masaar Admin')

@section('content')
<div class="mb-6">
    <h2 class="text-2xl font-bold text-gray-800">Submission Logs</h2>
    <p class="text-gray-500">ZATCA submission history and status</p>
</div>

<!-- State Summary -->
<div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
    <a href="{{ route('admin.logs') }}" class="bg-white rounded-lg shadow p-4 text-center hover:bg-gray-50 {{ !$state ? 'ring-2 ring-blue-500' : '' }}">
        <p class="text-2xl font-bold">{{ array_sum($stateCounts->toArray()) }}</p>
        <p class="text-sm text-gray-500">All</p>
    </a>
    <a href="{{ route('admin.logs', ['state' => 'cleared']) }}" class="bg-white rounded-lg shadow p-4 text-center hover:bg-gray-50 {{ $state === 'cleared' ? 'ring-2 ring-green-500' : '' }}">
        <p class="text-2xl font-bold text-green-600">{{ $stateCounts['cleared'] ?? 0 }}</p>
        <p class="text-sm text-gray-500">Cleared</p>
    </a>
    <a href="{{ route('admin.logs', ['state' => 'reported']) }}" class="bg-white rounded-lg shadow p-4 text-center hover:bg-gray-50 {{ $state === 'reported' ? 'ring-2 ring-blue-500' : '' }}">
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
        <input type="text" name="organization_id" value="{{ $orgId }}" placeholder="Organization ID" class="border rounded px-3 py-2 text-sm">
        <button type="submit" class="px-4 py-2 bg-gray-600 text-white rounded text-sm">Filter</button>
        <a href="{{ route('admin.logs', $state ? ['state' => $state] : []) }}" class="px-4 py-2 bg-gray-200 text-gray-700 rounded text-sm">Clear Org Filter</a>
    </form>
</div>

<!-- Logs Table -->
<div class="bg-white rounded-lg shadow overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Submission</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Organization</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">State</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">ZATCA Status</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Error</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Created</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @forelse($logs as $log)
            <tr class="hover:bg-gray-50">
                <td class="px-6 py-4 text-sm">
                    <div class="font-medium">{{ Str::limit($log->id, 8) }}</div>
                    <div class="text-xs text-gray-500">Inv: {{ Str::limit($log->invoice_id, 8) }}</div>
                </td>
                <td class="px-6 py-4 text-sm">{{ $log->organization_name ?? Str::limit($log->organization_id, 8) }}</td>
                <td class="px-6 py-4">
                    <span class="px-2 py-1 text-xs font-medium rounded bg-gray-100 text-gray-800">
                        {{ $log->submission_type }}
                    </span>
                </td>
                <td class="px-6 py-4">
                    <span class="px-2 py-1 text-xs font-medium rounded-full
                        {{ $log->state === 'cleared' ? 'bg-green-100 text-green-800' : '' }}
                        {{ $log->state === 'reported' ? 'bg-blue-100 text-blue-800' : '' }}
                        {{ $log->state === 'rejected' ? 'bg-red-100 text-red-800' : '' }}
                        {{ $log->state === 'failed' ? 'bg-orange-100 text-orange-800' : '' }}
                        {{ in_array($log->state, ['pending', 'queued', 'submitted']) ? 'bg-yellow-100 text-yellow-800' : '' }}">
                        {{ $log->state }}
                    </span>
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
                    @if($log->last_error_message)
                        <span class="text-red-600" title="{{ $log->last_error_message }}">
                            {{ $log->last_error_code }}: {{ Str::limit($log->last_error_message, 30) }}
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
            <tr>
                <td colspan="7" class="px-6 py-4 text-center text-gray-500">No logs found</td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">
    {{ $logs->appends(request()->query())->links() }}
</div>
@endsection
