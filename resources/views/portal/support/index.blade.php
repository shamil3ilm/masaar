@extends('layouts.portal')

@section('title', 'Support')

@section('content')
<div class="mb-6 flex flex-wrap items-start justify-between gap-4">
    <div>
        <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Support</h2>
        <p class="text-gray-500 dark:text-gray-400">Ask us anything about ZATCA, your invoices or your account. Everyone in your company sees these tickets.</p>
    </div>
    <x-ui.button :href="route('portal.support.create')">New ticket</x-ui.button>
</div>

<div class="bg-white dark:bg-gray-900 rounded-lg shadow overflow-hidden">
    <x-ui.table>
        <x-slot:head>
            <x-ui.th>Ticket</x-ui.th>
            <x-ui.th>Subject</x-ui.th>
            <x-ui.th>Status</x-ui.th>
            <x-ui.th>Last activity</x-ui.th>
        </x-slot:head>
        @forelse ($tickets as $ticket)
        <tr class="hover:bg-gray-50 dark:hover:bg-gray-950">
            <td class="px-6 py-4 text-sm font-mono">
                <a href="{{ route('portal.support.show', $ticket->id) }}" class="text-teal-700 dark:text-teal-300 hover:underline">{{ $ticket->reference() }}</a>
            </td>
            <td class="px-6 py-4 text-sm text-gray-800 dark:text-gray-100">
                {{ $ticket->subject }}
                @if ($ticket->priority === \App\Domains\Support\Enums\TicketPriority::Urgent)
                    <x-ui.badge tone="danger" label="Urgent" />
                @endif
            </td>
            <td class="px-6 py-4"><x-ui.badge :tone="$ticket->status->tone()" :label="$ticket->status->label()" /></td>
            <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $ticket->last_activity_at->diffForHumans() }}</td>
        </tr>
        @empty
        <x-ui.empty :columns="4">No tickets yet</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

<div class="mt-4">
    {{ $tickets->links() }}
</div>
@endsection
