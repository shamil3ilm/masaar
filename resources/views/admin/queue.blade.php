@extends('layouts.admin')

@section('title', 'Offline Queue - Masaar Admin')

@section('content')
<div class="mb-6 flex justify-between items-center">
    <div>
        <h2 class="text-2xl font-bold text-gray-800">Offline Queue</h2>
        <p class="text-gray-500">Queued invoices pending ZATCA submission</p>
    </div>
    <form action="{{ route('admin.queue.process') }}" method="POST" class="inline">
        @csrf
        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
            Process Queue
        </button>
    </form>
</div>

<!-- Stats -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-lg shadow p-4 text-center">
        <p class="text-2xl font-bold text-yellow-600">{{ $stats['pending'] ?? 0 }}</p>
        <p class="text-sm text-gray-500">Pending</p>
    </div>
    <div class="bg-white rounded-lg shadow p-4 text-center">
        <p class="text-2xl font-bold text-blue-600">{{ $stats['processing'] ?? 0 }}</p>
        <p class="text-sm text-gray-500">Processing</p>
    </div>
    <div class="bg-white rounded-lg shadow p-4 text-center">
        <p class="text-2xl font-bold text-green-600">{{ $stats['completed'] ?? 0 }}</p>
        <p class="text-sm text-gray-500">Completed</p>
    </div>
    <div class="bg-white rounded-lg shadow p-4 text-center">
        <p class="text-2xl font-bold text-red-600">{{ $stats['failed'] ?? 0 }}</p>
        <p class="text-sm text-gray-500">Failed</p>
    </div>
</div>

<!-- Filters -->
<div class="bg-white rounded-lg shadow p-4 mb-6">
    <form method="GET" class="flex flex-wrap gap-4">
        <select name="state" class="border rounded px-3 py-2 text-sm">
            <option value="">All States</option>
            <option value="pending" {{ $state === 'pending' ? 'selected' : '' }}>Pending</option>
            <option value="processing" {{ $state === 'processing' ? 'selected' : '' }}>Processing</option>
            <option value="completed" {{ $state === 'completed' ? 'selected' : '' }}>Completed</option>
            <option value="failed" {{ $state === 'failed' ? 'selected' : '' }}>Failed</option>
        </select>
        <input type="text" name="org_id" value="{{ $orgId }}" placeholder="Organization ID" class="border rounded px-3 py-2 text-sm">
        <button type="submit" class="px-4 py-2 bg-gray-600 text-white rounded text-sm">Filter</button>
        <a href="{{ route('admin.queue') }}" class="px-4 py-2 bg-gray-200 text-gray-700 rounded text-sm">Clear</a>
    </form>
</div>

<!-- Queue Items -->
<div class="bg-white rounded-lg shadow overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invoice</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Organization</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">State</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Attempts</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Queued At</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Error</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @forelse($items as $item)
            <tr class="hover:bg-gray-50">
                <td class="px-6 py-4 text-sm">{{ Str::limit($item->invoice_id, 8) }}</td>
                <td class="px-6 py-4 text-sm">{{ $item->organization_name ?? Str::limit($item->org_id, 8) }}</td>
                <td class="px-6 py-4">
                    <span class="px-2 py-1 text-xs font-medium rounded-full
                        {{ $item->state === 'pending' ? 'bg-yellow-100 text-yellow-800' : '' }}
                        {{ $item->state === 'processing' ? 'bg-blue-100 text-blue-800' : '' }}
                        {{ $item->state === 'completed' ? 'bg-green-100 text-green-800' : '' }}
                        {{ $item->state === 'failed' ? 'bg-red-100 text-red-800' : '' }}">
                        {{ $item->state }}
                    </span>
                </td>
                <td class="px-6 py-4 text-sm">{{ $item->attempts }}/{{ $item->max_attempts }}</td>
                <td class="px-6 py-4 text-sm text-gray-500">{{ \Carbon\Carbon::parse($item->queued_at)->diffForHumans() }}</td>
                <td class="px-6 py-4 text-sm text-red-600">{{ Str::limit($item->last_error, 50) }}</td>
                <td class="px-6 py-4">
                    @if($item->state === 'failed')
                    <form action="{{ route('admin.queue.retry', $item->id) }}" method="POST" class="inline">
                        @csrf
                        <button type="submit" class="text-blue-600 hover:text-blue-800 text-sm">Retry</button>
                    </form>
                    @endif
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="7" class="px-6 py-4 text-center text-gray-500">No queue items found</td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">
    {{ $items->appends(request()->query())->links() }}
</div>
@endsection
