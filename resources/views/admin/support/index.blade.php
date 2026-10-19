@extends('layouts.admin')

@section('title', 'Support')

@section('content')
<x-ui.page-header title="Support" description="Customers' tickets: urgent first, then whoever has waited longest" />

<form method="GET" action="{{ route('admin.support.index') }}" class="mb-6 bg-surface rounded-card border border-line shadow-card p-4 flex flex-wrap items-end gap-4">
    <div>
        <x-ui.field label="Status">
            <x-ui.select id="status" name="status">
                @foreach ($statuses as $option)
                    <option value="{{ $option->value }}" @selected($status === $option)>{{ $option->label() }}</option>
                @endforeach
                <option value="all" @selected($status === null)>Any status</option>
            </x-ui.select>
        </x-ui.field>
    </div>
    <div>
        <x-ui.field label="Assigned">
            <x-ui.select id="assigned" name="assigned">
                <option value="">Anyone</option>
                <option value="mine" @selected($assigned === 'mine')>Me</option>
                <option value="unassigned" @selected($assigned === 'unassigned')>Nobody</option>
            </x-ui.select>
        </x-ui.field>
    </div>
    <div class="flex-1 min-w-48">
        <x-ui.field label="Search">
            <x-ui.input id="q" name="q" type="text" value="{{ $search }}" placeholder="Ticket number, subject or company" />
        </x-ui.field>
    </div>
    <div class="flex items-center gap-2">
        <x-ui.button variant="secondary">Filter</x-ui.button>
        <x-ui.button variant="ghost" :href="route('admin.support.index')">Reset</x-ui.button>
    </div>
</form>

<div class="bg-surface rounded-card border border-line shadow-card overflow-hidden">
    <x-ui.table :primary="[2, 1, 3]">
        <x-slot:head>
            <x-ui.th>Ticket</x-ui.th>
            <x-ui.th>Company</x-ui.th>
            <x-ui.th>Subject</x-ui.th>
            <x-ui.th>Status</x-ui.th>
            <x-ui.th>Assigned</x-ui.th>
            <x-ui.th>Last activity</x-ui.th>
        </x-slot:head>
        @forelse ($tickets as $ticket)
        <tr class="hover:bg-sunken">
            <td class="px-6 py-4 text-sm font-mono">
                <a href="{{ route('admin.support.show', $ticket->id) }}" class="text-accent hover:underline">{{ $ticket->reference() }}</a>
            </td>
            <td class="px-6 py-4 text-sm text-muted">{{ $ticket->organization?->name }}</td>
            <td class="px-6 py-4 text-sm text-fg">
                {{ $ticket->subject }}
                @if ($ticket->priority === \App\Domains\Support\Enums\TicketPriority::Urgent)
                    <x-ui.badge tone="danger" label="Urgent" />
                @endif
            </td>
            <td class="px-6 py-4"><x-ui.badge :tone="$ticket->status->tone()" :label="$ticket->status->label()" /></td>
            <td class="px-6 py-4 text-sm text-muted">{{ $ticket->assignee?->name ?? '-' }}</td>
            <td class="px-6 py-4 text-sm text-muted">{{ $ticket->last_activity_at->diffForHumans() }}</td>
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
