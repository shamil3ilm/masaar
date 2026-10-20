@extends('layouts.portal')

@section('title', __('Requests'))

@section('content')
<x-ui.page-header :title="__('Requests')" :description="__('Ask us for help, for work done for your company, or for something new. Everyone in your company sees these requests.')">
    <x-slot:actions>
        <x-ui.button variant="secondary" :href="route('portal.board.index')">{{ __('Feature board') }}</x-ui.button>
        <x-ui.button :href="route('portal.support.create')">{{ __('New request') }}</x-ui.button>
    </x-slot:actions>
</x-ui.page-header>

<div class="bg-surface rounded-card border border-line shadow-card overflow-hidden">
    <x-ui.table :primary="[2, 3, 0]">
        <x-slot:head>
            <x-ui.th>{{ __('Request') }}</x-ui.th>
            <x-ui.th>{{ __('Kind') }}</x-ui.th>
            <x-ui.th>{{ __('Subject') }}</x-ui.th>
            <x-ui.th>{{ __('Status') }}</x-ui.th>
            <x-ui.th>{{ __('Last activity') }}</x-ui.th>
        </x-slot:head>
        @forelse ($tickets as $ticket)
        <tr class="hover:bg-sunken">
            <td class="px-6 py-4 text-sm font-mono">
                <a href="{{ route('portal.support.show', $ticket->id) }}" class="text-accent hover:underline">{{ $ticket->reference() }}</a>
            </td>
            <td class="px-6 py-4 text-sm text-muted">{{ $ticket->kind->label() }}</td>
            <td class="px-6 py-4 text-sm text-fg">
                {{ $ticket->subject }}
                @if ($ticket->priority === \App\Domains\Support\Enums\TicketPriority::Urgent)
                    <x-ui.badge tone="danger" :label="__('Urgent')" />
                @endif
            </td>
            <td class="px-6 py-4">
                @if ($ticket->stage)
                    <x-ui.badge :tone="$ticket->stage->tone()" :label="$ticket->stage->label()" />
                @else
                    <x-ui.badge :tone="$ticket->status->tone()" :label="$ticket->status->label()" />
                @endif
            </td>
            <td class="px-6 py-4 text-sm text-muted">{{ $ticket->last_activity_at->diffForHumans() }}</td>
        </tr>
        @empty
        <x-ui.empty :columns="5">{{ __('No requests yet') }}</x-ui.empty>
        @endforelse
    </x-ui.table>
</div>

<div class="mt-4">
    {{ $tickets->links() }}
</div>
@endsection
