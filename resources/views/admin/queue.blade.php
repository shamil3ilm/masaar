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
        <x-ui.button>Process Queue</x-ui.button>
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
        <x-ui.field label="State">
        <x-ui.select name="state">
            <option value="">All States</option>
            <option value="pending" {{ $state === 'pending' ? 'selected' : '' }}>Pending</option>
            <option value="processing" {{ $state === 'processing' ? 'selected' : '' }}>Processing</option>
            <option value="completed" {{ $state === 'completed' ? 'selected' : '' }}>Completed</option>
            <option value="failed" {{ $state === 'failed' ? 'selected' : '' }}>Failed</option>
        </x-ui.select>
        </x-ui.field>
        <x-ui.field label="Organization ID">
            <x-ui.input type="text" name="org_id" value="{{ $orgId }}" />
        </x-ui.field>
        <div class="flex items-end gap-2">
            <x-ui.button variant="secondary">Filter</x-ui.button>
            <x-ui.button variant="quiet" :href="route('admin.queue')">Clear</x-ui.button>
        </div>
    </form>
</div>

<!-- Queue Items -->
<div class="bg-white rounded-lg shadow overflow-hidden">
    <x-ui.table>
        <x-slot:head>
            <x-ui.th>Invoice</x-ui.th>
            <x-ui.th>Organization</x-ui.th>
            <x-ui.th>State</x-ui.th>
            <x-ui.th>Attempts</x-ui.th>
            <x-ui.th>Queued At</x-ui.th>
            <x-ui.th>Error</x-ui.th>
            <x-ui.th>Actions</x-ui.th>
        </x-slot:head>

        @forelse($items as $item)
        <tr class="hover:bg-gray-50">
            <td class="px-6 py-4 text-sm">{{ Str::limit($item->invoice_id, 8) }}</td>
            <td class="px-6 py-4 text-sm">{{ $item->organization_name ?? Str::limit($item->org_id, 8) }}</td>
            <td class="px-6 py-4">
                {{-- failed was red here and orange on the submissions and
                     logs screens, where red means the authority refused the
                     document. An unanswered item is not a refused one. --}}
                <x-ui.badge :state="$item->state" />
            </td>
            <td class="px-6 py-4 text-sm">{{ $item->attempts }}/{{ $item->max_attempts }}</td>
            <td class="px-6 py-4 text-sm text-gray-500">{{ \Carbon\Carbon::parse($item->queued_at)->diffForHumans() }}</td>
            <td class="px-6 py-4 text-sm text-red-600">{{ Str::limit($item->last_error, 50) }}</td>
            <td class="px-6 py-4">
                @if($item->state === 'failed')
                <form action="{{ route('admin.queue.retry', $item->id) }}" method="POST" class="inline">
                    @csrf
                    <button type="submit" class="text-teal-700 hover:text-teal-900 text-sm">Retry</button>
                </form>
                @endif
            </td>
        </tr>
        @empty
        <x-ui.empty :columns="7">No queue items found</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

<div class="mt-4">
    {{ $items->appends(request()->query())->links() }}
</div>
@endsection
