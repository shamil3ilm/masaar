@extends('layouts.admin')

@section('title', 'Offline Queue')

@section('content')
<div class="mb-6 flex justify-between items-center">
    <div>
        <h1 class="text-2xl font-semibold tracking-tight text-fg">Offline Queue</h1>
        <p class="mt-1 max-w-3xl text-sm text-muted">Queued invoices pending ZATCA submission</p>
    </div>
    @admincan('operations.run')
    <form action="{{ route('admin.queue.process') }}" method="POST" class="inline">
        @csrf
        <x-ui.button>Process Queue</x-ui.button>
    </form>
    @endadmincan
</div>

<!-- Stats -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-surface rounded-card border border-line shadow-card p-4 text-center">
        <p class="text-2xl font-semibold text-warning">{{ $stats['pending'] ?? 0 }}</p>
        <p class="text-sm text-muted">Pending</p>
    </div>
    <div class="bg-surface rounded-card border border-line shadow-card p-4 text-center">
        <p class="text-2xl font-semibold text-info">{{ $stats['processing'] ?? 0 }}</p>
        <p class="text-sm text-muted">Processing</p>
    </div>
    <div class="bg-surface rounded-card border border-line shadow-card p-4 text-center">
        <p class="text-2xl font-semibold text-success">{{ $stats['completed'] ?? 0 }}</p>
        <p class="text-sm text-muted">Completed</p>
    </div>
    <div class="bg-surface rounded-card border border-line shadow-card p-4 text-center">
        <p class="text-2xl font-semibold text-danger">{{ $stats['failed'] ?? 0 }}</p>
        <p class="text-sm text-muted">Failed</p>
    </div>
</div>

<x-ui.list-filters :list="$list" />

<!-- Queue Items -->
<div class="bg-surface rounded-card border border-line shadow-card overflow-hidden">
    <x-ui.table :primary="[0, 1, 2]">
        <x-slot:head>
            <x-ui.th>Invoice</x-ui.th>
            <x-ui.th>Organization</x-ui.th>
            <x-ui.th>State</x-ui.th>
            <x-ui.th :list="$list" sort="attempts">Attempts</x-ui.th>
            <x-ui.th :list="$list" sort="newest">Queued At</x-ui.th>
            <x-ui.th>Error</x-ui.th>
            <x-ui.th>Actions</x-ui.th>
        </x-slot:head>

        @forelse($items as $item)
        <tr class="hover:bg-sunken">
            <td class="px-6 py-4 text-sm">{{ Str::limit($item->invoice_id, 8) }}</td>
            <td class="px-6 py-4 text-sm">{{ $item->organization_name ?? Str::limit($item->org_id, 8) }}</td>
            <td class="px-6 py-4">
                {{-- failed was red here and orange on the submissions and
                     logs screens, where red means the authority refused the
                     document. An unanswered item is not a refused one. --}}
                <x-ui.badge :state="$item->state" />
            </td>
            <td class="px-6 py-4 text-sm">{{ $item->attempts }}/{{ $item->max_attempts }}</td>
            <td class="px-6 py-4 text-sm text-muted">{{ \Carbon\Carbon::parse($item->queued_at)->diffForHumans() }}</td>
            <td class="px-6 py-4 text-sm text-danger">{{ Str::limit($item->last_error, 50) }}</td>
            <td class="px-6 py-4">
                @if($item->state === 'failed')
                @admincan('operations.run')
                <form action="{{ route('admin.queue.retry', $item->id) }}" method="POST" class="inline">
                    @csrf
                    <x-ui.link-button>Retry</x-ui.link-button>
                </form>
                @endadmincan
                @endif
            </td>
        </tr>
        @empty
        <x-ui.empty :columns="7">No queue items found</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

{{ $items->links() }}
@endsection
