@extends('layouts.admin')

@section('title', 'Support')

@section('content')
<div class="mb-6">
    <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Support</h2>
    <p class="text-gray-500 dark:text-gray-400">Customers' tickets: urgent first, then whoever has waited longest</p>
</div>

<form method="GET" action="{{ route('admin.support.index') }}" class="mb-6 bg-white dark:bg-gray-900 rounded-lg shadow p-4 flex flex-wrap items-end gap-4">
    <div>
        <label for="status" class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Status</label>
        <x-ui.select id="status" name="status">
            @foreach ($statuses as $option)
                <option value="{{ $option->value }}" @selected($status === $option)>{{ $option->label() }}</option>
            @endforeach
            <option value="all" @selected($status === null)>Any status</option>
        </x-ui.select>
    </div>
    <div>
        <label for="assigned" class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Assigned</label>
        <x-ui.select id="assigned" name="assigned">
            <option value="">Anyone</option>
            <option value="mine" @selected($assigned === 'mine')>Me</option>
            <option value="unassigned" @selected($assigned === 'unassigned')>Nobody</option>
        </x-ui.select>
    </div>
    <div class="flex-1 min-w-48">
        <label for="q" class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase mb-1">Search</label>
        <x-ui.input id="q" name="q" type="text" value="{{ $search }}" placeholder="Ticket number, subject or company" />
    </div>
    <div class="flex items-center gap-2">
        <x-ui.button variant="secondary">Filter</x-ui.button>
        <x-ui.button variant="quiet" :href="route('admin.support.index')">Reset</x-ui.button>
    </div>
</form>

<div class="bg-white dark:bg-gray-900 rounded-lg shadow overflow-hidden">
    <x-ui.table>
        <x-slot:head>
            <x-ui.th>Ticket</x-ui.th>
            <x-ui.th>Company</x-ui.th>
            <x-ui.th>Subject</x-ui.th>
            <x-ui.th>Status</x-ui.th>
            <x-ui.th>Assigned</x-ui.th>
            <x-ui.th>Last activity</x-ui.th>
        </x-slot:head>
        @forelse ($tickets as $ticket)
        <tr class="hover:bg-gray-50 dark:hover:bg-gray-950">
            <td class="px-6 py-4 text-sm font-mono">
                <a href="{{ route('admin.support.show', $ticket->id) }}" class="text-teal-700 dark:text-teal-300 hover:underline">{{ $ticket->reference() }}</a>
            </td>
            <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $ticket->organization?->name }}</td>
            <td class="px-6 py-4 text-sm text-gray-800 dark:text-gray-100">
                {{ $ticket->subject }}
                @if ($ticket->priority === \App\Domains\Support\Enums\TicketPriority::Urgent)
                    <x-ui.badge tone="danger" label="Urgent" />
                @endif
            </td>
            <td class="px-6 py-4"><x-ui.badge :tone="$ticket->status->tone()" :label="$ticket->status->label()" /></td>
            <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $ticket->assignee?->name ?? '-' }}</td>
            <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $ticket->last_activity_at->diffForHumans() }}</td>
        </tr>
        @empty
        <x-ui.empty :columns="6">No tickets match</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

<div class="mt-4">
    {{ $tickets->links() }}
</div>
@endsection
